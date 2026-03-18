<?php

use Illuminate\Support\Facades\File;
use Kekser\LaravelPaladin\Models\HealingAttempt;
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

test('it protects in-progress worktrees', function () {
    $basePath = '/tmp/paladin/worktrees';
    $worktreeDir = $basePath.'/paladin-fix-in-progress';

    HealingAttempt::create([
        'issue_id' => 'ISSUE-1',
        'issue_type' => 'Exception',
        'severity' => 'error',
        'status' => 'in_progress',
        'worktree_path' => $worktreeDir,
        'message' => 'In progress fix',
    ]);

    $worktreeManager = Mockery::mock(WorktreeManager::class);
    $worktreeManager->shouldReceive('getBasePath')->andReturn($basePath);
    $this->app->instance(WorktreeManager::class, $worktreeManager);

    File::shouldReceive('exists')->with($basePath)->andReturn(true);
    File::shouldReceive('directories')->with($basePath)->andReturn([$worktreeDir]);

    $this->artisan('paladin:cleanup --all')
        ->expectsOutputToContain('No worktrees to clean up!')
        ->assertExitCode(0);
});
