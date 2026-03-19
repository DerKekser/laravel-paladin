<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
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

it('handles boost install failure', function () {
    config(['paladin.worktree.laravel_boost_enabled' => true]);
    File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(true);

    $processResultFail = Mockery::mock(ProcessResult::class);
    $processResultFail->shouldReceive('successful')->andReturn(false);
    $processResultFail->shouldReceive('failed')->andReturn(true);
    $processResultFail->shouldReceive('output')->andReturn('Error Output');
    $processResultFail->shouldReceive('exitCode')->andReturn(1);

    $processResultSuccess = Mockery::mock(ProcessResult::class);
    $processResultSuccess->shouldReceive('successful')->andReturn(true);
    $processResultSuccess->shouldReceive('failed')->andReturn(false);
    $processResultSuccess->shouldReceive('output')->andReturn('No commands found in the boost namespace.');

    $processPending = Mockery::mock(PendingProcess::class);
    $processPending->shouldReceive('path')->with($this->worktreePath)->andReturnSelf();
    $processPending->shouldReceive('run')->with('php artisan list boost')->andReturn($processResultSuccess);
    $processPending->shouldReceive('run')->with('php artisan boost:install --no-interaction')->andReturn($processResultFail);

    Process::swap($processPending);

    $result = $this->service->ensureBoosted($this->worktreePath);

    expect($result)->toBeFalse();

    Process::spy();
});

it('handles boost update failure', function () {
    config(['paladin.worktree.laravel_boost_enabled' => true]);
    File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(true);

    $processResultFail = Mockery::mock(ProcessResult::class);
    $processResultFail->shouldReceive('successful')->andReturn(false);
    $processResultFail->shouldReceive('failed')->andReturn(true);
    $processResultFail->shouldReceive('output')->andReturn('Error Output');
    $processResultFail->shouldReceive('exitCode')->andReturn(1);

    $processResultSuccess = Mockery::mock(ProcessResult::class);
    $processResultSuccess->shouldReceive('successful')->andReturn(true);
    $processResultSuccess->shouldReceive('failed')->andReturn(false);
    $processResultSuccess->shouldReceive('output')->andReturn('boost:install  Install boost');

    $processPending = Mockery::mock(PendingProcess::class);
    $processPending->shouldReceive('path')->with($this->worktreePath)->andReturnSelf();
    $processPending->shouldReceive('run')->with('php artisan list boost')->andReturn($processResultSuccess);
    $processPending->shouldReceive('run')->with('php artisan boost:update --no-interaction')->andReturn($processResultFail);

    Process::swap($processPending);

    $result = $this->service->ensureBoosted($this->worktreePath);

    expect($result)->toBeFalse();

    Process::spy();
});

it('handles command failure', function () {
    config(['paladin.worktree.laravel_boost_enabled' => true]);
    File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(true);

    Process::fake([
        '*' => function () {
            throw new Exception('Process failed');
        },
    ]);

    $result = $this->service->ensureBoosted($this->worktreePath);

    expect($result)->toBeFalse();
});
