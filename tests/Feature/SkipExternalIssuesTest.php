<?php

namespace Kekser\LaravelPaladin\Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Jobs\ProcessSelfHealingJob;
use Kekser\LaravelPaladin\Models\HealingAttempt;
use Kekser\LaravelPaladin\Services\LogScanner;
use Kekser\LaravelPaladin\Tests\TestCase;

class SkipExternalIssuesTest extends TestCase
{
    protected string $testLogPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->testLogPath = storage_path('logs/test-paladin.log');
        Config::set('paladin.log.storage_path', storage_path('logs'));
        Config::set('paladin.log.channels', ['test-paladin']);
        Config::set('paladin.log.levels', ['error', 'critical']);
    }

    protected function tearDown(): void
    {
        if (File::exists($this->testLogPath)) {
            File::delete($this->testLogPath);
        }

        parent::tearDown();
    }

    /** @test */
    public function it_skips_log_entries_with_only_vendor_files_in_stack_trace()
    {
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
        $this->assertEmpty($entries, 'Log entries with only vendor files should be filtered out');
    }

    /** @test */
    public function it_includes_log_entries_with_app_files_in_stack_trace()
    {
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
        $this->assertNotEmpty($entries, 'Log entries with app files should be included');
    }

    /** @test */
    public function it_includes_log_entries_with_mixed_app_and_vendor_files()
    {
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
        $this->assertNotEmpty($entries, 'Log entries with mixed files should be included if at least one is internal');
    }

    /** @test */
    public function it_creates_skipped_healing_attempt_for_external_issues()
    {
        $this->runMigrations();

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
        $reflector = new \ReflectionClass($job);
        $method = $reflector->getMethod('processIssue');
        $method->setAccessible(true);
        $method->invoke($job, $issue);

        // Assert healing attempt was created with 'skipped' status
        $this->assertDatabaseHas('healing_attempts', [
            'issue_id' => 'test-external-issue-123',
            'status' => 'skipped',
        ]);

        $attempt = HealingAttempt::where('issue_id', 'test-external-issue-123')->first();
        $this->assertNotNull($attempt);
        $this->assertEquals('skipped', $attempt->status);
        $this->assertStringContainsString('outside project boundaries', $attempt->error_message);
    }

    /** @test */
    public function it_processes_fixable_issues_with_internal_files()
    {
        $this->runMigrations();

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
        $reflector = new \ReflectionClass($job);
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
    }

    protected function runMigrations(): void
    {
        $this->artisan('migrate', ['--database' => 'testing']);
    }
}
