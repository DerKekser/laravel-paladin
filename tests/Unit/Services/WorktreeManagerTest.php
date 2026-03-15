<?php

namespace Kekser\LaravelPaladin\Tests\Unit\Services;

use Illuminate\Support\Facades\File;
use Kekser\LaravelPaladin\Services\WorktreeManager;
use Kekser\LaravelPaladin\Tests\TestCase;

class WorktreeManagerTest extends TestCase
{
    protected WorktreeManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new WorktreeManager;

        // Clean up any existing test worktrees
        $basePath = $this->manager->getBasePath();
        if (File::exists($basePath)) {
            $directories = File::directories($basePath);
            foreach ($directories as $dir) {
                if (str_starts_with(basename($dir), 'paladin-fix-')) {
                    File::deleteDirectory($dir);
                }
            }
        }
    }

    protected function tearDown(): void
    {
        // Clean up test worktrees
        $basePath = $this->manager->getBasePath();
        if (File::exists($basePath)) {
            $directories = File::directories($basePath);
            foreach ($directories as $dir) {
                if (str_starts_with(basename($dir), 'paladin-fix-')) {
                    $this->manager->remove($dir);
                }
            }
        }

        parent::tearDown();
    }

    /** @test */
    public function it_gets_base_path_from_config()
    {
        config(['paladin.worktree.base_path' => 'worktrees']);

        $manager = new WorktreeManager;
        $basePath = $manager->getBasePath();

        $this->assertStringEndsWith('worktrees', $basePath);
        $this->assertTrue($this->isAbsolutePath($basePath));
    }

    /** @test */
    public function it_handles_absolute_paths()
    {
        config(['paladin.worktree.base_path' => '/tmp/paladin-worktrees']);

        $manager = new WorktreeManager;

        $this->assertEquals('/tmp/paladin-worktrees', $manager->getBasePath());
    }

    /** @test */
    public function it_generates_worktree_name_with_issue_id()
    {
        config(['paladin.worktree.naming_pattern' => 'paladin-fix-{issue_id}']);

        $manager = new WorktreeManager;
        $method = $this->getProtectedMethod(WorktreeManager::class, 'generateWorktreeName');

        $name = $method->invoke($manager, 'abc123def456');

        $this->assertStringStartsWith('paladin-fix-abc123de', $name);
    }

    /** @test */
    public function it_generates_worktree_name_with_timestamp()
    {
        config(['paladin.worktree.naming_pattern' => 'paladin-fix-{timestamp}']);

        $manager = new WorktreeManager;
        $method = $this->getProtectedMethod(WorktreeManager::class, 'generateWorktreeName');

        $name = $method->invoke($manager, 'test-issue');

        $this->assertStringStartsWith('paladin-fix-', $name);
        $this->assertMatchesRegularExpression('/paladin-fix-\d{14}/', $name);
    }

    /** @test */
    public function it_generates_worktree_name_with_both_placeholders()
    {
        config(['paladin.worktree.naming_pattern' => 'paladin-fix-{issue_id}-{timestamp}']);

        $manager = new WorktreeManager;
        $method = $this->getProtectedMethod(WorktreeManager::class, 'generateWorktreeName');

        $name = $method->invoke($manager, 'abc123def456');

        $this->assertMatchesRegularExpression('/paladin-fix-abc123de-\d{14}/', $name);
    }

    /** @test */
    public function it_checks_if_path_is_absolute_unix()
    {
        $method = $this->getProtectedMethod(WorktreeManager::class, 'isAbsolutePath');

        $this->assertTrue($method->invoke($this->manager, '/tmp/test'));
        $this->assertFalse($method->invoke($this->manager, 'relative/path'));
        $this->assertFalse($method->invoke($this->manager, ''));
    }

    /** @test */
    public function it_checks_if_path_is_absolute_windows()
    {
        $method = $this->getProtectedMethod(WorktreeManager::class, 'isAbsolutePath');

        $this->assertTrue($method->invoke($this->manager, 'C:/temp/test'));
        $this->assertTrue($method->invoke($this->manager, 'D:/temp/test'));
    }

    /** @test */
    public function it_gets_full_path_for_worktree()
    {
        config(['paladin.worktree.base_path' => '/tmp/paladin-test']);

        $manager = new WorktreeManager;
        $method = $this->getProtectedMethod(WorktreeManager::class, 'getFullPath');

        $fullPath = $method->invoke($manager, 'test-worktree');

        $this->assertEquals('/tmp/paladin-test/test-worktree', $fullPath);
    }

    /** @test */
    public function it_checks_if_worktree_exists()
    {
        // Create a temporary directory
        $testPath = sys_get_temp_dir().'/paladin-test-'.uniqid();
        File::makeDirectory($testPath);

        $this->assertTrue($this->manager->exists($testPath));

        File::deleteDirectory($testPath);

        $this->assertFalse($this->manager->exists($testPath));
    }

    /** @test */
    public function it_returns_false_for_non_existent_worktree()
    {
        $this->assertFalse($this->manager->exists('/non/existent/path'));
    }

    /** @test */
    public function it_returns_false_for_file_instead_of_directory()
    {
        $testFile = sys_get_temp_dir().'/paladin-test-file-'.uniqid();
        file_put_contents($testFile, 'test');

        $this->assertFalse($this->manager->exists($testFile));

        unlink($testFile);
    }

    /** @test */
    public function it_removes_worktree_directory()
    {
        // Create a temporary directory
        $testPath = sys_get_temp_dir().'/paladin-test-'.uniqid();
        File::makeDirectory($testPath);
        File::put($testPath.'/test.txt', 'test content');

        $this->assertTrue(File::exists($testPath));

        $result = $this->manager->remove($testPath);

        $this->assertTrue($result);
        $this->assertFalse(File::exists($testPath));
    }

    /** @test */
    public function it_returns_true_when_removing_non_existent_worktree()
    {
        $result = $this->manager->remove('/non/existent/path');

        $this->assertTrue($result);
    }

    /** @test */
    public function it_cleans_up_old_worktrees()
    {
        config([
            'paladin.worktree.base_path' => sys_get_temp_dir().'/paladin-cleanup-test',
            'paladin.worktree.cleanup_after_days' => 7,
        ]);

        $manager = new WorktreeManager;
        $basePath = $manager->getBasePath();

        // Create base directory
        File::makeDirectory($basePath, 0755, true);

        // Create an old worktree (modified 10 days ago)
        $oldWorktree = $basePath.'/paladin-fix-old-'.uniqid();
        File::makeDirectory($oldWorktree);
        touch($oldWorktree, time() - (10 * 86400));

        // Create a recent worktree (modified 1 day ago)
        $recentWorktree = $basePath.'/paladin-fix-recent-'.uniqid();
        File::makeDirectory($recentWorktree);
        touch($recentWorktree, time() - (1 * 86400));

        // Create a non-paladin directory (should not be touched)
        $otherDir = $basePath.'/other-dir-'.uniqid();
        File::makeDirectory($otherDir);
        touch($otherDir, time() - (10 * 86400));

        $this->assertTrue(File::exists($oldWorktree));
        $this->assertTrue(File::exists($recentWorktree));
        $this->assertTrue(File::exists($otherDir));

        $removed = $manager->cleanupOld();

        $this->assertEquals(1, $removed);
        $this->assertFalse(File::exists($oldWorktree));
        $this->assertTrue(File::exists($recentWorktree));
        $this->assertTrue(File::exists($otherDir));

        // Clean up
        File::deleteDirectory($basePath);
    }

    /** @test */
    public function it_returns_zero_when_base_path_does_not_exist()
    {
        config(['paladin.worktree.base_path' => '/tmp/non-existent-'.uniqid()]);

        $manager = new WorktreeManager;
        $removed = $manager->cleanupOld();

        $this->assertEquals(0, $removed);
    }

    /** @test */
    public function it_respects_cleanup_after_days_config()
    {
        config([
            'paladin.worktree.base_path' => sys_get_temp_dir().'/paladin-cleanup-test-2',
            'paladin.worktree.cleanup_after_days' => 3,
        ]);

        $manager = new WorktreeManager;
        $basePath = $manager->getBasePath();

        File::makeDirectory($basePath, 0755, true);

        // Create a worktree modified 5 days ago (should be removed with 3-day cutoff)
        $oldWorktree = $basePath.'/paladin-fix-test-'.uniqid();
        File::makeDirectory($oldWorktree);
        touch($oldWorktree, time() - (5 * 86400));

        $removed = $manager->cleanupOld();

        $this->assertEquals(1, $removed);
        $this->assertFalse(File::exists($oldWorktree));

        // Clean up
        File::deleteDirectory($basePath);
    }

    /**
     * Helper to get protected method for testing
     */
    protected function getProtectedMethod(string $class, string $methodName): \ReflectionMethod
    {
        $reflection = new \ReflectionClass($class);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method;
    }

    /**
     * Helper to check if path is absolute (copied from WorktreeManager for testing)
     */
    protected function isAbsolutePath(string $path): bool
    {
        if (strlen($path) === 0) {
            return false;
        }

        return $path[0] === '/' || (strlen($path) > 2 && $path[1] === ':');
    }
}
