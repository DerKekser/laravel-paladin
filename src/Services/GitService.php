<?php

namespace Kekser\LaravelPaladin\Services;

use Illuminate\Support\Facades\Log;

class GitService
{
    /**
     * Check if a remote repository is configured for the given path.
     */
    public function hasRemote(string $path): bool
    {
        $command = sprintf(
            'cd %s && git remote get-url origin 2>&1',
            escapeshellarg($path)
        );

        exec($command, $output, $returnCode);

        return $returnCode === 0;
    }

    /**
     * Create and checkout a new branch.
     */
    public function createBranch(string $path, string $branchName): bool
    {
        $commands = [
            sprintf('cd %s', escapeshellarg($path)),
            sprintf('git checkout -b %s', escapeshellarg($branchName)),
        ];

        exec(implode(' && ', $commands), $output, $returnCode);

        if ($returnCode !== 0) {
            Log::error('[Paladin] Failed to create branch', [
                'path' => $path,
                'branch' => $branchName,
                'output' => implode("\n", $output),
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
        $commands = [
            sprintf('cd %s', escapeshellarg($path)),
            'git add .',
            sprintf('git commit -m %s', escapeshellarg($message)),
        ];

        exec(implode(' && ', $commands), $output, $returnCode);

        if ($returnCode !== 0) {
            Log::error('[Paladin] Failed to commit changes', [
                'path' => $path,
                'output' => implode("\n", $output),
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
        $command = sprintf(
            'cd %s && git push origin %s',
            escapeshellarg($path),
            escapeshellarg($branchName)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            Log::error('[Paladin] Failed to push branch', [
                'path' => $path,
                'branch' => $branchName,
                'output' => implode("\n", $output),
            ]);

            return false;
        }

        return true;
    }
}
