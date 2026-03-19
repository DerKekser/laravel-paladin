<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Kekser\LaravelPaladin\Models\HealingAttempt;
use Kekser\LaravelPaladin\Pr\PullRequestManager;
use Kekser\LaravelPaladin\Services\FileBoundaryValidator;
use Kekser\LaravelPaladin\Services\GitService;
use Kekser\LaravelPaladin\Services\IssuePrioritizer;
use Kekser\LaravelPaladin\Services\LogScanner;
use Kekser\LaravelPaladin\Services\OpenCodeRunner;
use Kekser\LaravelPaladin\Services\SelfHealingOrchestrator;
use Kekser\LaravelPaladin\Services\TemplateGenerator;
use Kekser\LaravelPaladin\Services\TestRunner;
use Kekser\LaravelPaladin\Services\WorktreeManager;
use Kekser\LaravelPaladin\Services\WorktreeSetup;
use Mockery;

beforeEach(function () {
    $this->testLogPath = storage_path('logs/test-paladin.log');
    Config::set('paladin.log.storage_path', storage_path('logs'));
    Config::set('paladin.log.channels', ['test-paladin']);
    Config::set('paladin.log.levels', ['error', 'critical']);
});

afterEach(function () {
    if (File::exists($this->testLogPath)) {
        File::delete($this->testLogPath);
    }
});

test('it skips log entries with only vendor files in stack trace', function () {
    // Create a log entry with only vendor files in stack trace
    $logContent = sprintf(
        "[%s] testing.error: SQLSTATE[42S02]: Base table or view not found\n".
        "#0 /var/www/vendor/laravel/framework/src/Illuminate/Database/Connection.php(671): PDOStatement->execute()\n".
        "#1 /var/www/vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php(2345): Illuminate\\Database\\Connection->select()\n",
        now()->format('Y-m-d H:i:s')
    );

    File::put($this->testLogPath, $logContent);

    $scanner = app(LogScanner::class);
    $scanner->resetLastScanTime();
    $entries = $scanner->scan();

    // Should be filtered out during scan (before AI processing)
    expect($entries)->toBeEmpty('Log entries with only vendor files should be filtered out');
});

test('it includes log entries with app files in stack trace', function () {
    // Create a log entry with app files
    $logContent = sprintf(
        "[%s] testing.error: Call to undefined method\n".
        "#0 /var/www/app/Http/Controllers/UserController.php(45): App\\Services\\UserService->createUser()\n".
        "#1 /var/www/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): App\\Http\\Controllers\\UserController->store()\n",
        now()->format('Y-m-d H:i:s')
    );

    File::put($this->testLogPath, $logContent);

    $scanner = app(LogScanner::class);
    $scanner->resetLastScanTime();
    $entries = $scanner->scan();

    // Should NOT be filtered out (has app files)
    expect($entries)->not->toBeEmpty('Log entries with app files should be included');
});

test('it includes log entries with mixed app and vendor files', function () {
    // Create a log entry with mixed files
    $logContent = sprintf(
        "[%s] testing.error: Type error in controller\n".
        "#0 /var/www/vendor/symfony/console/Application.php(123): someFunction()\n".
        "#1 /var/www/app/Console/Commands/MyCommand.php(67): Symfony\\Component\\Console\\Application->run()\n",
        now()->format('Y-m-d H:i:s')
    );

    File::put($this->testLogPath, $logContent);

    $scanner = app(LogScanner::class);
    $scanner->resetLastScanTime();
    $entries = $scanner->scan();

    // Should be included (has at least one app file)
    expect($entries)->not->toBeEmpty('Log entries with mixed files should be included if at least one is internal');
});

test('it creates skipped healing attempt for external issues', function () {
    $this->artisan('migrate', ['--database' => 'testing']);

    // Create a mock issue with only vendor files
    $issue = [
        'id' => 'test-external-issue-123',
        'type' => 'DatabaseException',
        'severity' => 'high',
        'title' => 'Database connection failed',
        'message' => 'SQLSTATE[HY000] [2002] Connection refused',
        'stack_trace' => "#0 /var/www/vendor/laravel/framework/src/Illuminate/Database/Connection.php(671): PDOStatement->execute()\n",
        'affected_files' => [
            'vendor/laravel/framework/src/Illuminate/Database/Connection.php',
            'vendor/laravel/framework/src/Illuminate/Database/Connectors/Connector.php',
        ],
        'suggested_fix' => 'Check database credentials',
        'log_level' => 'error',
    ];

    // Mock the FileBoundaryValidator to return external-only validation
    $mockValidator = Mockery::mock(FileBoundaryValidator::class);
    $mockValidator->shouldReceive('analyzeIssue')
        ->with($issue['affected_files'])
        ->andReturn([
            'is_fixable' => false,
            'reason' => 'All affected files are outside project boundaries',
            'internal_files' => [],
            'external_files' => $issue['affected_files'],
        ]);
    $this->app->instance(FileBoundaryValidator::class, $mockValidator);

    // Create orchestrator with mocked dependencies
    $orchestrator = new SelfHealingOrchestrator(
        new IssuePrioritizer,
        new TemplateGenerator,
        $mockValidator,
        Mockery::mock(WorktreeManager::class),
        Mockery::mock(WorktreeSetup::class),
        Mockery::mock(OpenCodeRunner::class),
        Mockery::mock(TestRunner::class),
        Mockery::mock(GitService::class),
        Mockery::mock(PullRequestManager::class)
    );

    // Process the issue through orchestrator
    $orchestrator->processIssues([$issue]);

    // Assert healing attempt was created with 'skipped' status
    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'test-external-issue-123',
        'status' => 'skipped',
    ]);

    $attempt = HealingAttempt::where('issue_id', 'test-external-issue-123')->first();
    expect($attempt)->not->toBeNull();
    expect($attempt->status)->toBe('skipped');
    expect($attempt->error_message)->toContain('outside project boundaries');
});

test('it processes fixable issues with internal files', function () {
    $this->artisan('migrate', ['--database' => 'testing']);

    // Create a mock issue with app files
    $issue = [
        'id' => 'test-internal-issue-456',
        'type' => 'RuntimeException',
        'severity' => 'high',
        'title' => 'Undefined variable',
        'message' => 'Undefined variable: user',
        'stack_trace' => "#0 /var/www/app/Http/Controllers/UserController.php(42): someFunction()\n",
        'affected_files' => [
            'app/Http/Controllers/UserController.php',
        ],
        'suggested_fix' => 'Initialize the variable',
        'log_level' => 'error',
    ];

    // Mock the FileBoundaryValidator to return fixable validation
    $mockValidator = Mockery::mock(FileBoundaryValidator::class);
    $mockValidator->shouldReceive('analyzeIssue')
        ->with($issue['affected_files'])
        ->andReturn([
            'is_fixable' => true,
            'reason' => '',
            'internal_files' => $issue['affected_files'],
            'external_files' => [],
        ]);
    $this->app->instance(FileBoundaryValidator::class, $mockValidator);

    // Mock dependencies to prevent actual fix attempts
    Config::set('paladin.testing.max_fix_attempts', 0);

    // Create orchestrator with mocked dependencies
    $orchestrator = new SelfHealingOrchestrator(
        new IssuePrioritizer,
        new TemplateGenerator,
        $mockValidator,
        Mockery::mock(WorktreeManager::class),
        Mockery::mock(WorktreeSetup::class),
        Mockery::mock(OpenCodeRunner::class),
        Mockery::mock(TestRunner::class),
        Mockery::mock(GitService::class),
        Mockery::mock(PullRequestManager::class)
    );

    // Process the issue - should NOT skip
    $orchestrator->processIssues([$issue]);

    // Verify it didn't create a 'skipped' attempt
    $this->assertDatabaseMissing('healing_attempts', [
        'issue_id' => 'test-internal-issue-456',
        'status' => 'skipped',
    ]);
});
