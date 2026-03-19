<?php

namespace Kekser\LaravelPaladin\Tests\Unit\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Kekser\LaravelPaladin\Services\LaravelBoostService;
use Kekser\LaravelPaladin\Tests\TestCase;

class LaravelBoostServiceTest extends TestCase
{
    protected LaravelBoostService $service;

    protected string $worktreePath = '/mock/worktree';

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LaravelBoostService;
    }

    public function test_it_skips_if_disabled_in_config()
    {
        config(['paladin.worktree.laravel_boost_enabled' => false]);
        Process::fake();

        $result = $this->service->ensureBoosted($this->worktreePath);

        $this->assertTrue($result);
        Process::assertNothingRan();
    }

    public function test_it_runs_boost_install_if_not_installed()
    {
        config(['paladin.worktree.laravel_boost_enabled' => true]);
        File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(true);

        Process::fake([
            'php artisan list boost' => Process::result('No commands found in the boost namespace.', 1),
            'php artisan boost:install --no-interaction' => Process::result('Installed', 0),
        ]);

        $result = $this->service->ensureBoosted($this->worktreePath);

        $this->assertTrue($result);
        Process::assertRan(function ($process) {
            return str_contains($process->command, 'boost:install');
        });
    }

    public function test_it_runs_boost_update_if_already_installed()
    {
        config(['paladin.worktree.laravel_boost_enabled' => true]);
        File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(true);

        Process::fake([
            'php artisan list boost' => Process::result('boost:install  Install boost', 0),
            'php artisan boost:update --no-interaction' => Process::result('Updated', 0),
        ]);

        $result = $this->service->ensureBoosted($this->worktreePath);

        $this->assertTrue($result);
        Process::assertRan(function ($process) {
            return str_contains($process->command, 'boost:update');
        });
        Process::assertNotRan(function ($process) {
            return str_contains($process->command, 'boost:install');
        });
    }

    public function test_it_returns_false_if_artisan_missing()
    {
        config(['paladin.worktree.laravel_boost_enabled' => true]);
        File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(false);
        Process::fake();

        $result = $this->service->ensureBoosted($this->worktreePath);

        $this->assertFalse($result);
        Process::assertNothingRan();
    }

    public function test_it_handles_command_failure()
    {
        config(['paladin.worktree.laravel_boost_enabled' => true]);
        File::shouldReceive('exists')->with($this->worktreePath.'/artisan')->andReturn(true);

        Process::fake([
            '*' => function () {
                throw new \Exception('Process failed');
            },
        ]);

        $result = $this->service->ensureBoosted($this->worktreePath);

        $this->assertFalse($result);
    }
}
