<?php

namespace Kekser\LaravelPaladin\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class WorktreeSetup
{
    /**
     * Set up a worktree with all necessary dependencies and configuration.
     */
    public function setup(string $worktreePath): bool
    {
        try {
            Log::info('[Paladin] Setting up worktree', ['path' => $worktreePath]);

            // 1. Composer install
            if (config('paladin.worktree.setup.composer_install', true)) {
                $this->runComposerInstall($worktreePath);
            }

            // 2. Environment setup
            if (config('paladin.worktree.setup.copy_env', true)) {
                $this->setupEnvironment($worktreePath);
            }

            // 3. Create storage directories
            $this->createStorageDirectories($worktreePath);

            // 4. Laravel Boost
            app(LaravelBoostService::class)->ensureBoosted($worktreePath);

            // 5. Custom commands
            $this->runCustomCommands($worktreePath);

            Log::info('[Paladin] Worktree setup completed successfully');

            return true;
        } catch (\Exception $e) {
            Log::error('[Paladin] Worktree setup failed', [
                'path' => $worktreePath,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Run composer install in the worktree.
     */
    protected function runComposerInstall(string $worktreePath): void
    {
        Log::info('[Paladin] Running composer install in worktree');

        // Check if composer.json exists
        if (! File::exists($worktreePath.'/composer.json')) {
            throw new RuntimeException('composer.json not found in worktree');
        }

        // Get composer flags from config
        $flags = config('paladin.worktree.setup.composer_flags', '--no-interaction --prefer-dist --no-dev');

        // Build the command
        $result = Process::path($worktreePath)->run(sprintf('composer install %s', $flags));

        if (! $result->successful()) {
            throw new RuntimeException(
                'Composer install failed: '.$result->output()
            );
        }

        Log::info('[Paladin] Composer install completed');
    }

    /**
     * Set up environment file in the worktree.
     */
    protected function setupEnvironment(string $worktreePath): void
    {
        Log::info('[Paladin] Setting up environment file');

        // Determine source env file
        $envSource = config('paladin.worktree.setup.env_source', '.env.testing');
        $mainProjectEnv = base_path($envSource);

        // Fall back to .env if preferred source doesn't exist
        if (! File::exists($mainProjectEnv)) {
            Log::debug('[Paladin] Env source not found, falling back to .env', [
                'attempted' => $envSource,
            ]);
            $mainProjectEnv = base_path('.env');
        }

        // Copy .env file if it exists
        if (File::exists($mainProjectEnv)) {
            $destinationEnv = $worktreePath.'/.env';
            File::copy($mainProjectEnv, $destinationEnv);

            Log::info('[Paladin] Environment file copied', [
                'source' => $mainProjectEnv,
                'destination' => $destinationEnv,
            ]);

            // Generate app key if configured and key is missing
            if (config('paladin.worktree.setup.generate_key', true)) {
                $this->ensureAppKey($worktreePath);
            }
        } else {
            Log::warning('[Paladin] No .env file found to copy');
        }
    }

    /**
     * Ensure the APP_KEY is set in the environment file.
     */
    protected function ensureAppKey(string $worktreePath): void
    {
        $envPath = $worktreePath.'/.env';

        if (! File::exists($envPath)) {
            return;
        }

        $envContent = File::get($envPath);

        // Check if APP_KEY is empty or missing
        if (! preg_match('/^APP_KEY=\S+/m', $envContent)) {
            Log::info('[Paladin] Generating application key');

            $result = Process::path($worktreePath)->run('php artisan key:generate --force');

            if (! $result->successful()) {
                Log::warning('[Paladin] Failed to generate app key', [
                    'output' => $result->output(),
                ]);
            } else {
                Log::info('[Paladin] Application key generated');
            }
        }
    }

    /**
     * Create necessary storage directories in the worktree.
     */
    protected function createStorageDirectories(string $worktreePath): void
    {
        Log::info('[Paladin] Creating storage directories');

        $directories = [
            'storage/app',
            'storage/app/public',
            'storage/framework',
            'storage/framework/cache',
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/testing',
            'storage/framework/views',
            'storage/logs',
            'bootstrap/cache',
        ];

        foreach ($directories as $directory) {
            $fullPath = $worktreePath.'/'.$directory;

            if (! File::exists($fullPath)) {
                File::makeDirectory($fullPath, 0755, true);
            }
        }

        Log::info('[Paladin] Storage directories created');
    }

    /**
     * Run custom setup commands defined in configuration.
     */
    protected function runCustomCommands(string $worktreePath): void
    {
        $customCommands = config('paladin.worktree.setup.custom_commands', []);

        if (empty($customCommands)) {
            return;
        }

        Log::info('[Paladin] Running custom setup commands', [
            'count' => count($customCommands),
        ]);

        foreach ($customCommands as $command) {
            Log::debug('[Paladin] Running custom command', ['command' => $command]);

            // WARNING: Custom commands are executed directly without escaping.
            // Ensure custom_commands config only contains trusted input.
            $result = Process::path($worktreePath)->run($command);

            if (! $result->successful()) {
                Log::warning('[Paladin] Custom command failed', [
                    'command' => $command,
                    'output' => $result->output(),
                ]);
                // Don't throw - allow setup to continue even if custom commands fail
            } else {
                Log::debug('[Paladin] Custom command completed', [
                    'command' => $command,
                ]);
            }
        }

        Log::info('[Paladin] Custom setup commands completed');
    }
}
