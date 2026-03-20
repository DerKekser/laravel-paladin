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

it('returns false if artisan missing', function () {
    config(['paladin.worktree.laravel_boost_enabled' => true]);
    File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(false);
    Process::fake();

    $result = $this->service->ensureBoosted($this->worktreePath);

    expect($result)->toBeFalse();
    Process::assertNothingRan();
});

it('runs boost install when boost is in dependencies but not yet set up', function () {
    config(['paladin.worktree.laravel_boost_enabled' => true]);
    File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(true);
    File::shouldReceive('exists')->with($this->worktreePath.'/composer.json')->andReturn(true);
    File::shouldReceive('get')->with($this->worktreePath.'/composer.json')->andReturn(json_encode([
        'require' => ['laravel/boost' => '^2.3'],
    ]));

    Process::fake([
        'php artisan package:discover*' => Process::result('', 0),
        'php artisan list boost*' => Process::result('No commands found', 1),
        'php artisan boost:install*' => Process::result('Installed', 0),
    ]);

    $result = $this->service->ensureBoosted($this->worktreePath);

    expect($result)->toBeTrue();
    Process::assertRan(function ($process) {
        return str_contains($process->command, 'boost:install');
    });
});

it('runs boost update when boost is already installed', function () {
    config(['paladin.worktree.laravel_boost_enabled' => true]);
    File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(true);
    File::shouldReceive('exists')->with($this->worktreePath.'/composer.json')->andReturn(true);
    File::shouldReceive('get')->with($this->worktreePath.'/composer.json')->andReturn(json_encode([
        'require-dev' => ['laravel/boost' => '^2.3'],
    ]));

    Process::fake([
        'php artisan package:discover*' => Process::result('', 0),
        'php artisan list boost*' => Process::result("boost:install\nboost:update", 0),
        'php artisan boost:update*' => Process::result('Updated', 0),
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

it('installs boost via composer when not in dependencies', function () {
    config(['paladin.worktree.laravel_boost_enabled' => true]);
    File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(true);
    File::shouldReceive('exists')->with($this->worktreePath.'/composer.json')->andReturn(true);
    File::shouldReceive('get')->with($this->worktreePath.'/composer.json')->andReturn(json_encode([
        'require' => ['laravel/framework' => '^11.0'],
    ]));

    Process::fake([
        'composer require laravel/boost*' => Process::result('Installed', 0),
        'php artisan package:discover*' => Process::result('', 0),
        'php artisan list boost*' => Process::result('No commands found', 1),
        'php artisan boost:install*' => Process::result('Installed', 0),
    ]);

    $result = $this->service->ensureBoosted($this->worktreePath);

    expect($result)->toBeTrue();
    Process::assertRan(function ($process) {
        return str_contains($process->command, 'composer require laravel/boost');
    });
    Process::assertRan(function ($process) {
        return str_contains($process->command, 'boost:install');
    });
});

it('returns true even if composer install of boost fails', function () {
    config(['paladin.worktree.laravel_boost_enabled' => true]);
    File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(true);
    File::shouldReceive('exists')->with($this->worktreePath.'/composer.json')->andReturn(true);
    File::shouldReceive('get')->with($this->worktreePath.'/composer.json')->andReturn(json_encode([
        'require' => ['laravel/framework' => '^11.0'],
    ]));

    Process::fake([
        'composer require laravel/boost*' => Process::result('Error', 1),
    ]);

    $result = $this->service->ensureBoosted($this->worktreePath);

    expect($result)->toBeTrue();
    Process::assertRan(function ($process) {
        return str_contains($process->command, 'composer require laravel/boost');
    });
});

it('returns true even if boost install command fails', function () {
    config(['paladin.worktree.laravel_boost_enabled' => true]);
    File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(true);
    File::shouldReceive('exists')->with($this->worktreePath.'/composer.json')->andReturn(true);
    File::shouldReceive('get')->with($this->worktreePath.'/composer.json')->andReturn(json_encode([
        'require' => ['laravel/boost' => '^2.3'],
    ]));

    Process::fake([
        'php artisan package:discover*' => Process::result('', 0),
        'php artisan list boost*' => Process::result('No commands found', 1),
        'php artisan boost:install*' => Process::result('Error', 1),
    ]);

    $result = $this->service->ensureBoosted($this->worktreePath);

    expect($result)->toBeTrue();
});

it('returns true even if boost update command fails', function () {
    config(['paladin.worktree.laravel_boost_enabled' => true]);
    File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(true);
    File::shouldReceive('exists')->with($this->worktreePath.'/composer.json')->andReturn(true);
    File::shouldReceive('get')->with($this->worktreePath.'/composer.json')->andReturn(json_encode([
        'require' => ['laravel/boost' => '^2.3'],
    ]));

    Process::fake([
        'php artisan package:discover*' => Process::result('', 0),
        'php artisan list boost*' => Process::result("boost:install\nboost:update", 0),
        'php artisan boost:update*' => Process::result('Error', 1),
    ]);

    $result = $this->service->ensureBoosted($this->worktreePath);

    expect($result)->toBeTrue();
});

it('returns true on exception', function () {
    config(['paladin.worktree.laravel_boost_enabled' => true]);
    File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(true);
    File::shouldReceive('exists')->with($this->worktreePath.'/composer.json')->andReturn(true);
    File::shouldReceive('get')->with($this->worktreePath.'/composer.json')->andThrow(new Exception('Disk error'));
    Process::fake();

    $result = $this->service->ensureBoosted($this->worktreePath);

    expect($result)->toBeTrue();
});

it('handles invalid json in composer.json', function () {
    config(['paladin.worktree.laravel_boost_enabled' => true]);
    File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(true);
    File::shouldReceive('exists')->with($this->worktreePath.'/composer.json')->andReturn(true);
    File::shouldReceive('get')->with($this->worktreePath.'/composer.json')->andReturn('invalid json');

    Process::fake([
        'composer require laravel/boost*' => Process::result('Installed', 0),
        'php artisan package:discover*' => Process::result('', 0),
        'php artisan list boost*' => Process::result('No commands found', 1),
        'php artisan boost:install*' => Process::result('Installed', 0),
    ]);

    $result = $this->service->ensureBoosted($this->worktreePath);

    expect($result)->toBeTrue();
    Process::assertRan(function ($process) {
        return str_contains($process->command, 'composer require laravel/boost');
    });
});

it('handles missing composer.json', function () {
    config(['paladin.worktree.laravel_boost_enabled' => true]);
    File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(true);
    File::shouldReceive('exists')->with($this->worktreePath.'/composer.json')->andReturn(false);

    Process::fake([
        'composer require laravel/boost*' => Process::result('Installed', 0),
        'php artisan package:discover*' => Process::result('', 0),
        'php artisan list boost*' => Process::result('No commands found', 1),
        'php artisan boost:install*' => Process::result('Installed', 0),
    ]);

    $result = $this->service->ensureBoosted($this->worktreePath);

    expect($result)->toBeTrue();
    Process::assertRan(function ($process) {
        return str_contains($process->command, 'composer require laravel/boost');
    });
});
