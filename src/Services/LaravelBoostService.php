<?php

namespace Kekser\LaravelPaladin\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class LaravelBoostService
{
    /**
     * Ensure Laravel Boost is correctly set up in the given worktree.
     */
    public function ensureBoosted(string $worktreePath): bool
    {
        if (! config('paladin.worktree.laravel_boost_enabled', true)) {
            Log::debug('[Paladin] Laravel Boost is disabled in config, skipping.');

            return true;
        }

        if (! File::exists($worktreePath.'/artisan')) {
            Log::warning('[Paladin] artisan not found in worktree, cannot run boost commands.', ['path' => $worktreePath]);

            return false;
        }

        try {
            if ($this->isBoostInstalled($worktreePath)) {
                return $this->runBoostUpdate($worktreePath);
            }

            return $this->runBoostInstall($worktreePath);
        } catch (\Exception $e) {
            Log::error('[Paladin] Laravel Boost setup failed', [
                'path' => $worktreePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Check if Laravel Boost is already installed in the worktree.
     */
    protected function isBoostInstalled(string $worktreePath): bool
    {
        $result = Process::path($worktreePath)->run('php artisan list boost');

        return $result->successful() && str_contains($result->output(), 'boost:install');
    }

    /**
     * Run php artisan boost:install in the worktree.
     */
    protected function runBoostInstall(string $worktreePath): bool
    {
        Log::info('[Paladin] Running php artisan boost:install in worktree');

        $result = Process::path($worktreePath)->run('php artisan boost:install --no-interaction');

        if (! $result->successful()) {
            Log::error('[Paladin] php artisan boost:install failed', [
                'output' => $result->output(),
                'exit_code' => $result->exitCode(),
            ]);

            return false;
        }

        Log::info('[Paladin] php artisan boost:install completed');

        return true;
    }

    /**
     * Run php artisan boost:update in the worktree.
     */
    protected function runBoostUpdate(string $worktreePath): bool
    {
        Log::info('[Paladin] Running php artisan boost:update in worktree');

        $result = Process::path($worktreePath)->run('php artisan boost:update --no-interaction');

        if (! $result->successful()) {
            Log::error('[Paladin] php artisan boost:update failed', [
                'output' => $result->output(),
                'exit_code' => $result->exitCode(),
            ]);

            return false;
        }

        Log::info('[Paladin] php artisan boost:update completed');

        return true;
    }
}
