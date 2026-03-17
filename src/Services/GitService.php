<?php

namespace Kekser\LaravelPaladin\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

class GitService
{
    /**
     * Check if a remote repository is configured for the given path.
     */
    public function hasRemote(string $path): bool
    {
        $result = Process::path($path)->run(['git', 'remote', 'get-url', 'origin']);

        return $result->successful();
    }

    /**
     * Create and checkout a new branch.
     */
    public function createBranch(string $path, string $branchName): bool
    {
        $result = Process::path($path)->run(['git', 'checkout', '-b', $branchName]);

        if (! $result->successful()) {
            Log::error('[Paladin] Failed to create branch', [
                'path' => $path,
                'branch' => $branchName,
                'output' => $result->output(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Add all changes and commit.
     */
    public function commit(string $path, string $message): bool
    {
        $result = Process::path($path)->run(['sh', '-c', sprintf('git add . && git commit -m %s', escapeshellarg($message))]);

        if (! $result->successful()) {
            Log::error('[Paladin] Failed to commit changes', [
                'path' => $path,
                'output' => $result->output(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Push a branch to origin.
     */
    public function push(string $path, string $branchName): bool
    {
        $result = Process::path($path)->run(['git', 'push', 'origin', $branchName]);

        if (! $result->successful()) {
            Log::error('[Paladin] Failed to push branch', [
                'path' => $path,
                'branch' => $branchName,
                'output' => $result->output(),
            ]);

            return false;
        }

        return true;
    }
}
