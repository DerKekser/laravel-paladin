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
            // Check if boost is already in project's dependencies
            if (! $this->isBoostInDependencies($worktreePath)) {
                Log::info('[Paladin] Laravel Boost not found in project dependencies, installing...');
                if (! $this->installBoost($worktreePath)) {
                    Log::warning('[Paladin] Failed to install Laravel Boost, continuing without it.');

                    return false;
                }
            }

            // Run package discover to ensure commands are registered
            $this->runPackageDiscover($worktreePath);

            // Check if boost is installed (commands available)
            if ($this->isBoostInstalled($worktreePath)) {
                return $this->runBoostUpdate($worktreePath);
            }

            return $this->runBoostInstall($worktreePath);
        } catch (\Exception $e) {
            Log::warning('[Paladin] Laravel Boost setup failed, continuing without it', [
                'path' => $worktreePath,
                'error' => $e->getMessage(),
            ]);

            return true; // Optional - continue even on error
        }
    }

    /**
     * Check if Laravel Boost is in the project's composer.json dependencies.
     */
    protected function isBoostInDependencies(string $worktreePath): bool
    {
        $composerJsonPath = $worktreePath.'/composer.json';

        if (! File::exists($composerJsonPath)) {
            return false;
        }

        try {
            $composerContent = File::get($composerJsonPath);
            $composerData = json_decode($composerContent, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('[Paladin] Failed to parse composer.json', [
                    'path' => $composerJsonPath,
                    'error' => json_last_error_msg(),
                ]);

                return false;
            }

            // Check both require and require-dev
            $require = $composerData['require'] ?? [];
            $requireDev = $composerData['require-dev'] ?? [];

            return isset($require['laravel/boost']) || isset($requireDev['laravel/boost']);
        } catch (\Exception $e) {
            Log::warning('[Paladin] Error checking composer.json for boost', [
                'path' => $composerJsonPath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Install Laravel Boost in the worktree.
     */
    protected function installBoost(string $worktreePath): bool
    {
        Log::info('[Paladin] Installing Laravel Boost (latest version)', [
            'path' => $worktreePath,
        ]);

        $result = Process::path($worktreePath)->run('composer require laravel/boost --no-interaction --quiet');

        if (! $result->successful()) {
            Log::warning('[Paladin] Failed to install Laravel Boost via composer', [
                'output' => $result->output(),
                'exit_code' => $result->exitCode(),
            ]);

            return false;
        }

        Log::info('[Paladin] Laravel Boost installed successfully');

        return true;
    }

    /**
     * Run package:discover to register newly installed packages.
     */
    protected function runPackageDiscover(string $worktreePath): void
    {
        Log::debug('[Paladin] Running package:discover');

        $result = Process::path($worktreePath)->run('php artisan package:discover --ansi --quiet');

        if (! $result->successful()) {
            Log::warning('[Paladin] package:discover failed', [
                'output' => $result->output(),
                'exit_code' => $result->exitCode(),
            ]);
        } else {
            Log::debug('[Paladin] package:discover completed');
        }
    }

    /**
     * Check if Laravel Boost is already installed in the worktree (commands available).
     */
    protected function isBoostInstalled(string $worktreePath): bool
    {
        $result = Process::path($worktreePath)->run('php artisan list boost --format=txt');

        return $result->successful() && str_contains($result->output(), 'boost:');
    }

    /**
     * Run php artisan boost:install in the worktree.
     */
    protected function runBoostInstall(string $worktreePath): bool
    {
        Log::info('[Paladin] Running php artisan boost:install in worktree');

        $result = Process::path($worktreePath)->run('php artisan boost:install --no-interaction --quiet');

        if (! $result->successful()) {
            Log::warning('[Paladin] php artisan boost:install failed', [
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

        $result = Process::path($worktreePath)->run('php artisan boost:update --no-interaction --quiet');

        if (! $result->successful()) {
            Log::warning('[Paladin] php artisan boost:update failed', [
                'output' => $result->output(),
                'exit_code' => $result->exitCode(),
            ]);

            return false;
        }

        Log::info('[Paladin] php artisan boost:update completed');

        return true;
    }
}
