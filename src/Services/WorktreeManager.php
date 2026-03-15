<?php

namespace Kekser\LaravelPaladin\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;

class WorktreeManager
{
    protected string $basePath;

    protected string $namingPattern;

    public function __construct()
    {
        $this->basePath = config('paladin.worktree.base_path');
        $this->namingPattern = config('paladin.worktree.naming_pattern');
    }

    /**
     * Create a new git worktree for isolating fix attempts.
     */
    public function create(string $issueId, ?string $branch = null): array
    {
        // Generate worktree name
        $worktreeName = $this->generateWorktreeName($issueId);
        $worktreePath = $this->getFullPath($worktreeName);

        // Ensure base directory exists
        if (! File::exists(dirname($worktreePath))) {
            File::makeDirectory(dirname($worktreePath), 0755, true);
        }

        // Get the default branch to base the worktree on
        $defaultBranch = $branch ?? config('paladin.git.default_branch', 'main');

        // Generate a unique temporary branch name for the worktree
        // This avoids the "branch already in use" error when the default branch is checked out
        $tempBranchName = 'paladin-temp-'.substr($issueId, 0, 8).'-'.time();

        // Create the worktree with a new branch based on the default branch
        $command = sprintf(
            'git worktree add -b %s %s %s 2>&1',
            escapeshellarg($tempBranchName),
            escapeshellarg($worktreePath),
            escapeshellarg($defaultBranch)
        );

        exec($command, $output, $returnCode);

        if ($returnCode !== 0) {
            throw new RuntimeException(
                'Failed to create git worktree: '.implode("\n", $output)
            );
        }

        return [
            'name' => $worktreeName,
            'path' => $worktreePath,
        ];
    }

    /**
     * Remove a git worktree.
     */
    public function remove(string $worktreePath): bool
    {
        if (! File::exists($worktreePath)) {
            return true;
        }

        // Remove the worktree using git
        $command = sprintf(
            'git worktree remove %s --force 2>&1',
            escapeshellarg($worktreePath)
        );

        exec($command, $output, $returnCode);

        // Also remove the directory if git worktree remove failed
        if (File::exists($worktreePath)) {
            File::deleteDirectory($worktreePath);
        }

        return ! File::exists($worktreePath);
    }

    /**
     * Clean up old worktrees based on configuration.
     */
    public function cleanupOld(): int
    {
        $basePath = $this->getBasePath();

        if (! File::exists($basePath)) {
            return 0;
        }

        $cleanupAfterDays = config('paladin.worktree.cleanup_after_days', 7);
        $cutoffTime = time() - ($cleanupAfterDays * 86400);
        $removed = 0;

        $directories = File::directories($basePath);

        foreach ($directories as $dir) {
            if (str_starts_with(basename($dir), 'paladin-fix-')) {
                $modifiedTime = File::lastModified($dir);

                if ($modifiedTime < $cutoffTime) {
                    if ($this->remove($dir)) {
                        $removed++;
                    }
                }
            }
        }

        return $removed;
    }

    /**
     * Generate a worktree name based on the naming pattern.
     */
    protected function generateWorktreeName(string $issueId): string
    {
        $pattern = $this->namingPattern;

        $replacements = [
            '{issue_id}' => substr($issueId, 0, 8),
            '{timestamp}' => date('YmdHis'),
        ];

        return str_replace(
            array_keys($replacements),
            array_values($replacements),
            $pattern
        );
    }

    /**
     * Get the full path for a worktree name.
     */
    protected function getFullPath(string $worktreeName): string
    {
        $basePath = $this->getBasePath();

        return $basePath.'/'.$worktreeName;
    }

    /**
     * Get the absolute base path for worktrees.
     */
    public function getBasePath(): string
    {
        $basePath = $this->basePath;

        // Handle relative paths
        if (! $this->isAbsolutePath($basePath)) {
            $basePath = base_path($basePath);
        }

        return $basePath;
    }

    /**
     * Check if a path is absolute.
     */
    protected function isAbsolutePath(string $path): bool
    {
        if (strlen($path) === 0) {
            return false;
        }

        return $path[0] === '/' || (strlen($path) > 2 && $path[1] === ':');
    }

    /**
     * Check if a worktree exists.
     */
    public function exists(string $worktreePath): bool
    {
        return File::exists($worktreePath) && File::isDirectory($worktreePath);
    }
}
