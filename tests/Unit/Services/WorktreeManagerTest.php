<?php

use Illuminate\Support\Facades\File;
use Kekser\LaravelPaladin\Services\WorktreeManager;

beforeEach(function () {
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
});

afterEach(function () {
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
});

function getProtectedMethod(string $class, string $methodName): \ReflectionMethod
{
    $reflection = new \ReflectionClass($class);
    $method = $reflection->getMethod($methodName);
    $method->setAccessible(true);

    return $method;
}

function isAbsolutePath(string $path): bool
{
    if (strlen($path) === 0) {
        return false;
    }

    return $path[0] === '/' || (strlen($path) > 2 && $path[1] === ':');
}

test('it gets base path from config', function () {
    config(['paladin.worktree.base_path' => 'worktrees']);

    $manager = new WorktreeManager;
    $basePath = $manager->getBasePath();

    expect($basePath)->toEndWith('worktrees');
    expect(isAbsolutePath($basePath))->toBeTrue();
});

test('it handles absolute paths', function () {
    config(['paladin.worktree.base_path' => '/tmp/paladin-worktrees']);

    $manager = new WorktreeManager;

    expect($manager->getBasePath())->toBe('/tmp/paladin-worktrees');
});

test('it generates worktree name with issue id', function () {
    config(['paladin.worktree.naming_pattern' => 'paladin-fix-{issue_id}']);

    $manager = new WorktreeManager;
    $method = getProtectedMethod(WorktreeManager::class, 'generateWorktreeName');

    $name = $method->invoke($manager, 'abc123def456');

    expect($name)->toStartWith('paladin-fix-abc123de');
});

test('it generates worktree name with timestamp', function () {
    config(['paladin.worktree.naming_pattern' => 'paladin-fix-{timestamp}']);

    $manager = new WorktreeManager;
    $method = getProtectedMethod(WorktreeManager::class, 'generateWorktreeName');

    $name = $method->invoke($manager, 'test-issue');

    expect($name)->toStartWith('paladin-fix-');
    expect($name)->toMatch('/paladin-fix-\d{14}/');
});

test('it generates worktree name with both placeholders', function () {
    config(['paladin.worktree.naming_pattern' => 'paladin-fix-{issue_id}-{timestamp}']);

    $manager = new WorktreeManager;
    $method = getProtectedMethod(WorktreeManager::class, 'generateWorktreeName');

    $name = $method->invoke($manager, 'abc123def456');

    expect($name)->toMatch('/paladin-fix-abc123de-\d{14}/');
});

test('it checks if path is absolute unix', function () {
    $method = getProtectedMethod(WorktreeManager::class, 'isAbsolutePath');

    expect($method->invoke($this->manager, '/tmp/test'))->toBeTrue();
    expect($method->invoke($this->manager, 'relative/path'))->toBeFalse();
    expect($method->invoke($this->manager, ''))->toBeFalse();
});

test('it checks if path is absolute windows', function () {
    $method = getProtectedMethod(WorktreeManager::class, 'isAbsolutePath');

    expect($method->invoke($this->manager, 'C:/temp/test'))->toBeTrue();
    expect($method->invoke($this->manager, 'D:/temp/test'))->toBeTrue();
});

test('it gets full path for worktree', function () {
    config(['paladin.worktree.base_path' => '/tmp/paladin-test']);

    $manager = new WorktreeManager;
    $method = getProtectedMethod(WorktreeManager::class, 'getFullPath');

    $fullPath = $method->invoke($manager, 'test-worktree');

    expect($fullPath)->toBe('/tmp/paladin-test/test-worktree');
});

test('it checks if worktree exists', function () {
    // Create a temporary directory
    $testPath = sys_get_temp_dir().'/paladin-test-'.uniqid();
    File::makeDirectory($testPath);

    expect($this->manager->exists($testPath))->toBeTrue();

    File::deleteDirectory($testPath);

    expect($this->manager->exists($testPath))->toBeFalse();
});

test('it returns false for non existent worktree', function () {
    expect($this->manager->exists('/non/existent/path'))->toBeFalse();
});

test('it returns false for file instead of directory', function () {
    $testFile = sys_get_temp_dir().'/paladin-test-file-'.uniqid();
    file_put_contents($testFile, 'test');

    expect($this->manager->exists($testFile))->toBeFalse();

    unlink($testFile);
});

test('it removes worktree directory', function () {
    // Create a temporary directory
    $testPath = sys_get_temp_dir().'/paladin-test-'.uniqid();
    File::makeDirectory($testPath);
    File::put($testPath.'/test.txt', 'test content');

    expect(File::exists($testPath))->toBeTrue();

    $result = $this->manager->remove($testPath);

    expect($result)->toBeTrue();
    expect(File::exists($testPath))->toBeFalse();
});

test('it returns true when removing non existent worktree', function () {
    $result = $this->manager->remove('/non/existent/path');

    expect($result)->toBeTrue();
});

test('it cleans up old worktrees', function () {
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

    expect(File::exists($oldWorktree))->toBeTrue();
    expect(File::exists($recentWorktree))->toBeTrue();
    expect(File::exists($otherDir))->toBeTrue();

    $removed = $manager->cleanupOld();

    expect($removed)->toBe(1);
    expect(File::exists($oldWorktree))->toBeFalse();
    expect(File::exists($recentWorktree))->toBeTrue();
    expect(File::exists($otherDir))->toBeTrue();

    // Clean up
    File::deleteDirectory($basePath);
});

test('it returns zero when base path does not exist', function () {
    config(['paladin.worktree.base_path' => '/tmp/non-existent-'.uniqid()]);

    $manager = new WorktreeManager;
    $removed = $manager->cleanupOld();

    expect($removed)->toBe(0);
});

test('it respects cleanup after days config', function () {
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

    expect($removed)->toBe(1);
    expect(File::exists($oldWorktree))->toBeFalse();

    // Clean up
    File::deleteDirectory($basePath);
});
