<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Kekser\LaravelPaladin\Jobs\ProcessSelfHealingJob;
use Kekser\LaravelPaladin\Models\HealingAttempt;
use Kekser\LaravelPaladin\Services\LogScanner;

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

    $scanner = new LogScanner;
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

    $scanner = new LogScanner;
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

    $scanner = new LogScanner;
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

    // Simulate processing this issue
    $job = new ProcessSelfHealingJob([]);
    $reflector = new ReflectionClass($job);
    $method = $reflector->getMethod('processIssue');
    $method->setAccessible(true);
    $method->invoke($job, $issue);

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

    // Mock dependencies to prevent actual fix attempts
    Config::set('paladin.testing.max_fix_attempts', 0);

    $job = new ProcessSelfHealingJob([]);
    $reflector = new ReflectionClass($job);
    $method = $reflector->getMethod('processIssue');
    $method->setAccessible(true);

    // Should NOT skip this issue (has internal files)
    // Will attempt to process but max_attempts is 0 so won't create healing attempt
    $method->invoke($job, $issue);

    // Verify it didn't create a 'skipped' attempt
    $this->assertDatabaseMissing('healing_attempts', [
        'issue_id' => 'test-internal-issue-456',
        'status' => 'skipped',
    ]);
});
