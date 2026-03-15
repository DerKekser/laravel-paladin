<?php

namespace Kekser\LaravelPaladin\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kekser\LaravelPaladin\LaravelPaladinServiceProvider;
use Kekser\LaravelPaladin\Models\HealingAttempt;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Run package migrations
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getPackageProviders($app)
    {
        return [
            LaravelPaladinServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        // Setup default database to use sqlite :memory:
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Setup cache to use array driver
        $app['config']->set('cache.default', 'array');

        // Setup queue to use sync driver
        $app['config']->set('queue.default', 'sync');

        // Setup mail to use array driver
        $app['config']->set('mail.default', 'array');

        // Configure Paladin for testing
        $app['config']->set('paladin.enabled', true);
        $app['config']->set('paladin.ai.provider', 'gemini');
        $app['config']->set('paladin.ai.model', 'gemini-2.0-flash-exp');
        $app['config']->set('paladin.ai.temperature', 0.7);

        $app['config']->set('paladin.log.channels', ['stack']);
        $app['config']->set('paladin.log.levels', ['error', 'critical', 'alert', 'emergency']);
        $app['config']->set('paladin.log.storage_path', storage_path('logs'));

        $app['config']->set('paladin.worktree.base_path', '/tmp/test-worktrees');
        $app['config']->set('paladin.worktree.naming_pattern', 'paladin-fix-{issue_id}-{timestamp}');
        $app['config']->set('paladin.worktree.cleanup_after_success', true);
        $app['config']->set('paladin.worktree.cleanup_after_days', 7);

        $app['config']->set('paladin.testing.command', 'php artisan test');
        $app['config']->set('paladin.testing.timeout', 300);
        $app['config']->set('paladin.testing.max_fix_attempts', 3);
        $app['config']->set('paladin.testing.require_passing_tests', true);
        $app['config']->set('paladin.testing.skip_tests', false);

        $app['config']->set('paladin.git.default_branch', 'main');
        $app['config']->set('paladin.git.branch_prefix', 'paladin/fix');

        $app['config']->set('paladin.pr_provider', 'github');
        $app['config']->set('paladin.providers.github.token', 'test-github-token');
        $app['config']->set('paladin.providers.github.api_url', 'https://api.github.com');

        $app['config']->set('paladin.providers.azure-devops.organization', 'test-org');
        $app['config']->set('paladin.providers.azure-devops.project', 'test-project');
        $app['config']->set('paladin.providers.azure-devops.token', 'test-pat');

        $app['config']->set('paladin.providers.mail.to', 'test@example.com');
        $app['config']->set('paladin.providers.mail.from', 'paladin@example.com');

        $app['config']->set('paladin.opencode.binary_path', 'opencode');
        $app['config']->set('paladin.opencode.auto_install', true);
        $app['config']->set('paladin.opencode.timeout', 600);

        $app['config']->set('paladin.queue.enabled', true);
        $app['config']->set('paladin.queue.connection', null);
        $app['config']->set('paladin.queue.queue', 'default');
    }

    /**
     * Get the path to test fixtures directory.
     */
    protected function getFixturePath(string $path = ''): string
    {
        $base = __DIR__.'/Fixtures';

        return $path ? $base.'/'.ltrim($path, '/') : $base;
    }

    /**
     * Load a fixture file content.
     */
    protected function loadFixture(string $filename): string
    {
        $path = $this->getFixturePath($filename);

        if (! file_exists($path)) {
            throw new \RuntimeException("Fixture file not found: {$path}");
        }

        return file_get_contents($path);
    }

    /**
     * Load and decode a JSON fixture file.
     */
    protected function loadJsonFixture(string $filename): array
    {
        $content = $this->loadFixture($filename);

        return json_decode($content, true);
    }

    /**
     * Create a temporary directory for testing.
     */
    protected function createTempDirectory(string $prefix = 'paladin-test-'): string
    {
        $path = sys_get_temp_dir().'/'.uniqid($prefix);
        mkdir($path, 0755, true);

        return $path;
    }

    /**
     * Recursively delete a directory.
     */
    protected function deleteDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        // Safety check: only allow deletion of directories within system temp directory
        $realPath = realpath($path);
        $tempDir = realpath(sys_get_temp_dir());

        if ($realPath === false || $tempDir === false || ! str_starts_with($realPath, $tempDir)) {
            throw new \RuntimeException("Refusing to delete directory outside temp: {$path}");
        }

        $files = array_diff(scandir($path), ['.', '..']);

        foreach ($files as $file) {
            $filePath = $path.'/'.$file;
            is_dir($filePath) ? $this->deleteDirectory($filePath) : unlink($filePath);
        }

        rmdir($path);
    }

    /**
     * Assert that a HealingAttempt has a specific status.
     */
    protected function assertHealingAttemptStatus(int $id, string $expectedStatus): void
    {
        $attempt = HealingAttempt::find($id);

        $this->assertNotNull($attempt, "HealingAttempt with ID {$id} not found");
        $this->assertEquals($expectedStatus, $attempt->status,
            "Expected HealingAttempt status to be '{$expectedStatus}', got '{$attempt->status}'");
    }
}
