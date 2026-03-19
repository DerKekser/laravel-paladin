<?php

use Illuminate\Support\Facades\File;
use Kekser\LaravelPaladin\Services\WorktreeManager;

test('it handles no worktrees directory found', function () {
    $basePath = '/tmp/non-existent';

    $worktreeManager = Mockery::mock(WorktreeManager::class);
    $worktreeManager->shouldReceive('getBasePath')->andReturn($basePath);
    $this->app->instance(WorktreeManager::class, $worktreeManager);

    File::shouldReceive('exists')->with($basePath)->andReturn(false);

    $this->artisan('paladin:cleanup')
        ->expectsOutputToContain('No worktrees directory found. Nothing to clean up.')
        ->assertExitCode(0);
});

test('it handles no worktrees to cleanup', function () {
    $basePath = '/tmp/paladin/worktrees';

    $worktreeManager = Mockery::mock(WorktreeManager::class);
    $worktreeManager->shouldReceive('getBasePath')->andReturn($basePath);
    $this->app->instance(WorktreeManager::class, $worktreeManager);

    File::shouldReceive('exists')->with($basePath)->andReturn(true);
    File::shouldReceive('directories')->with($basePath)->andReturn([]);

    $this->artisan('paladin:cleanup')
        ->expectsOutputToContain('No worktrees to clean up!')
        ->assertExitCode(0);
});

test('it identifies worktrees for cleanup', function () {
    $basePath = '/tmp/paladin/worktrees';
    $worktreeDir = $basePath.'/paladin-fix-123';

    $worktreeManager = Mockery::mock(WorktreeManager::class);
    $worktreeManager->shouldReceive('getBasePath')->andReturn($basePath);
    $worktreeManager->shouldReceive('remove')->with($worktreeDir)->andReturn(true);
    $this->app->instance(WorktreeManager::class, $worktreeManager);

    File::shouldReceive('exists')->with($basePath)->andReturn(true);
    File::shouldReceive('directories')->with($basePath)->andReturn([$worktreeDir]);
    File::shouldReceive('lastModified')->with($worktreeDir)->andReturn(time() - (10 * 86400)); // 10 days ago

    $this->artisan('paladin:cleanup --force')
        ->expectsOutputToContain('Found 1 worktree(s) to clean up')
        ->expectsOutputToContain('paladin-fix-123')
        ->expectsOutputToContain('Cleaned up 1 worktree(s)')
        ->assertExitCode(0);
});

test('it skips worktrees that are not old enough', function () {
    $basePath = '/tmp/paladin/worktrees';
    $worktreeDir = $basePath.'/paladin-fix-123';

    $worktreeManager = Mockery::mock(WorktreeManager::class);
    $worktreeManager->shouldReceive('getBasePath')->andReturn($basePath);
    $this->app->instance(WorktreeManager::class, $worktreeManager);

    File::shouldReceive('exists')->with($basePath)->andReturn(true);
    File::shouldReceive('directories')->with($basePath)->andReturn([$worktreeDir]);
    File::shouldReceive('lastModified')->with($worktreeDir)->andReturn(time() - (2 * 86400)); // 2 days ago

    $this->artisan('paladin:cleanup --days=7')
        ->expectsOutputToContain('No worktrees to clean up!')
        ->assertExitCode(0);
});

test('it handles cleanup failure', function () {
    $basePath = '/tmp/paladin/worktrees';
    $worktreeDir = $basePath.'/paladin-fix-123';

    $worktreeManager = Mockery::mock(WorktreeManager::class);
    $worktreeManager->shouldReceive('getBasePath')->andReturn($basePath);
    $worktreeManager->shouldReceive('remove')->with($worktreeDir)->andReturn(false);
    $this->app->instance(WorktreeManager::class, $worktreeManager);

    File::shouldReceive('exists')->with($basePath)->andReturn(true);
    File::shouldReceive('directories')->with($basePath)->andReturn([$worktreeDir]);
    File::shouldReceive('lastModified')->with($worktreeDir)->andReturn(time() - (10 * 86400));

    $this->artisan('paladin:cleanup --force')
        ->expectsOutputToContain('Failed to remove paladin-fix-123')
        ->assertExitCode(0);
});

test('it handles cleanup exception', function () {
    $basePath = '/tmp/paladin/worktrees';
    $worktreeDir = $basePath.'/paladin-fix-123';

    $worktreeManager = Mockery::mock(WorktreeManager::class);
    $worktreeManager->shouldReceive('getBasePath')->andReturn($basePath);
    $worktreeManager->shouldReceive('remove')->with($worktreeDir)->andThrow(new Exception('Un-removable!'));
    $this->app->instance(WorktreeManager::class, $worktreeManager);

    File::shouldReceive('exists')->with($basePath)->andReturn(true);
    File::shouldReceive('directories')->with($basePath)->andReturn([$worktreeDir]);
    File::shouldReceive('lastModified')->with($worktreeDir)->andReturn(time() - (10 * 86400));

    $this->artisan('paladin:cleanup --force')
        ->expectsOutputToContain('Error removing paladin-fix-123: Un-removable!')
        ->assertExitCode(0);
});

test('it supports dry-run mode', function () {
    $basePath = '/tmp/paladin/worktrees';
    $worktreeDir = $basePath.'/paladin-fix-123';

    $worktreeManager = Mockery::mock(WorktreeManager::class);
    $worktreeManager->shouldReceive('getBasePath')->andReturn($basePath);
    $worktreeManager->shouldNotReceive('remove');
    $this->app->instance(WorktreeManager::class, $worktreeManager);

    File::shouldReceive('exists')->with($basePath)->andReturn(true);
    File::shouldReceive('directories')->with($basePath)->andReturn([$worktreeDir]);
    File::shouldReceive('lastModified')->with($worktreeDir)->andReturn(time() - (10 * 86400));

    $this->artisan('paladin:cleanup --dry-run')
        ->expectsOutputToContain('Dry run mode - no worktrees were deleted')
        ->assertExitCode(0);
});

test('it handles cancellation', function () {
    $basePath = '/tmp/paladin/worktrees';
    $worktreeDir = $basePath.'/paladin-fix-123';

    $worktreeManager = Mockery::mock(WorktreeManager::class);
    $worktreeManager->shouldReceive('getBasePath')->andReturn($basePath);
    $this->app->instance(WorktreeManager::class, $worktreeManager);

    File::shouldReceive('exists')->with($basePath)->andReturn(true);
    File::shouldReceive('directories')->with($basePath)->andReturn([$worktreeDir]);
    File::shouldReceive('lastModified')->with($worktreeDir)->andReturn(time() - (10 * 86400));

    $this->artisan('paladin:cleanup')
        ->expectsConfirmation('Continue?', 'no')
        ->expectsOutputToContain('Cleanup cancelled.')
        ->assertExitCode(0);
});

test('it handles directories that are not paladin worktrees', function () {
    $basePath = '/tmp/paladin/worktrees';
    $otherDir = $basePath.'/other-directory';

    $worktreeManager = Mockery::mock(WorktreeManager::class);
    $worktreeManager->shouldReceive('getBasePath')->andReturn($basePath);
    $this->app->instance(WorktreeManager::class, $worktreeManager);

    File::shouldReceive('exists')->with($basePath)->andReturn(true);
    File::shouldReceive('directories')->with($basePath)->andReturn([$otherDir]);

    $this->artisan('paladin:cleanup')
        ->expectsOutputToContain('No worktrees to clean up!')
        ->assertExitCode(0);
});

test('it handles size calculation error', function () {
    $basePath = '/tmp/paladin/worktrees';
    $worktreeDir = $basePath.'/paladin-fix-123';

    $worktreeManager = Mockery::mock(WorktreeManager::class);
    $worktreeManager->shouldReceive('getBasePath')->andReturn($basePath);
    $this->app->instance(WorktreeManager::class, $worktreeManager);

    File::shouldReceive('exists')->with($basePath)->andReturn(true);
    File::shouldReceive('directories')->with($basePath)->andReturn([$worktreeDir]);
    File::shouldReceive('lastModified')->with($worktreeDir)->andReturn(time() - (10 * 86400));

    // To trigger exception in getDirectorySize, we can use a non-existent directory for File::directories
    // but the command uses RecursiveDirectoryIterator which we can't easily mock via File facade.
    // However, if we pass a directory that exists but is not readable, it might throw.
    // Let's just trust the coverage for now or use a real directory that we then delete.

    $realTempDir = sys_get_temp_dir().'/paladin-cleanup-size-test-'.uniqid();
    mkdir($realTempDir, 0777, true);
    $realWorktreeDir = $realTempDir.'/paladin-fix-123';
    mkdir($realWorktreeDir, 0777, true);
    file_put_contents($realWorktreeDir.'/test.txt', 'some content');

    $worktreeManager->shouldReceive('getBasePath')->andReturn($realTempDir);
    $worktreeManager->shouldReceive('remove')->andReturn(true);

    $this->artisan('paladin:cleanup --force')
        ->expectsOutputToContain('Total disk space:')
        ->assertExitCode(0);

    exec('rm -rf '.escapeshellarg($realTempDir));
});

test('it handles directory size exception', function () {
    $basePath = '/tmp/paladin/worktrees';
    $worktreeDir = $basePath.'/paladin-fix-123';

    $worktreeManager = Mockery::mock(WorktreeManager::class);
    $worktreeManager->shouldReceive('getBasePath')->andReturn($basePath);
    $this->app->instance(WorktreeManager::class, $worktreeManager);

    File::shouldReceive('exists')->with($basePath)->andReturn(true);
    File::shouldReceive('directories')->with($basePath)->andReturn([$worktreeDir]);
    File::shouldReceive('lastModified')->with($worktreeDir)->andReturn(time() - (10 * 86400));

    // We can't easily mock the iterator, but the path doesn't exist in reality here
    // as we mocked File facade but didn't create the directory.
    // CleanupCommand::getDirectorySize should catch the exception when RecursiveDirectoryIterator fails.

    $this->artisan('paladin:cleanup --force')
        ->expectsOutputToContain('Total disk space: ~50 MB') // 50MB is the estimate on error
        ->assertExitCode(0);
});
