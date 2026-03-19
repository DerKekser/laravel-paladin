<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Kekser\LaravelPaladin\Services\LaravelBoostService;

beforeEach(function () {
    $this->service = app(LaravelBoostService::class);
    $this->worktreePath = '/mock/worktree';
});

it('skips if disabled in config', function () {
    config(['paladin.worktree.laravel_boost_enabled' => false]);
    Process::fake();

    $result = $this->service->ensureBoosted($this->worktreePath);

    expect($result)->toBeTrue();
    Process::assertNothingRan();
});

it('runs boost install if not installed', function () {
    config(['paladin.worktree.laravel_boost_enabled' => true]);
    File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(true);

    Process::fake([
        'php artisan list boost' => Process::result('No commands found in the boost namespace.', 1),
        'php artisan boost:install --no-interaction' => Process::result('Installed', 0),
    ]);

    $result = $this->service->ensureBoosted($this->worktreePath);

    expect($result)->toBeTrue();
    Process::assertRan(function ($process) {
        return str_contains($process->command, 'boost:install');
    });
});

it('runs boost update if already installed', function () {
    config(['paladin.worktree.laravel_boost_enabled' => true]);
    File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(true);

    Process::fake([
        'php artisan list boost' => Process::result('boost:install  Install boost', 0),
        'php artisan boost:update --no-interaction' => Process::result('Updated', 0),
    ]);

    $result = $this->service->ensureBoosted($this->worktreePath);

    expect($result)->toBeTrue();
    Process::assertRan(function ($process) {
        return str_contains($process->command, 'boost:update');
    });
    Process::assertNotRan(function ($process) {
        return str_contains($process->command, 'boost:install');
    });
});

it('returns false if artisan missing', function () {
    config(['paladin.worktree.laravel_boost_enabled' => true]);
    File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(false);
    Process::fake();

    $result = $this->service->ensureBoosted($this->worktreePath);

    expect($result)->toBeFalse();
    Process::assertNothingRan();
});

it('handles command failure', function () {
    config(['paladin.worktree.laravel_boost_enabled' => true]);
    File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(true);

    Process::fake([
        '*' => function () {
            throw new \Exception('Process failed');
        },
    ]);

    $result = $this->service->ensureBoosted($this->worktreePath);

    expect($result)->toBeFalse();
});
