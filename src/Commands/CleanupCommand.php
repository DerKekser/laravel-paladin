<?php

namespace Kekser\LaravelPaladin\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Kekser\LaravelPaladin\Models\HealingAttempt;
use Kekser\LaravelPaladin\Services\WorktreeManager;

class CleanupCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'paladin:cleanup
                            {--force : Skip confirmation prompt}
                            {--days= : Override cleanup threshold (default from config)}
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--all : Delete all worktrees including recent ones (use with caution)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old Paladin worktrees to free disk space';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🛡️  Laravel Paladin - Worktree Cleanup');
        $this->newLine();

        $worktreeManager = new WorktreeManager;
        $basePath = $this->getBasePath($worktreeManager);

        if (! File::exists($basePath)) {
            $this->comment('No worktrees directory found. Nothing to clean up.');

            return self::SUCCESS;
        }

        // Get options
        $force = $this->option('force');
        $daysOverride = $this->option('days');
        $dryRun = $this->option('dry-run');
        $all = $this->option('all');

        // Determine cleanup threshold
        $cleanupAfterDays = $daysOverride
            ? (int) $daysOverride
            : config('paladin.worktree.cleanup_after_days', 7);

        // Find worktrees to clean up
        $worktrees = $this->findWorktreesToCleanup($basePath, $cleanupAfterDays, $all);

        if (empty($worktrees)) {
            $this->info('✓ No worktrees to clean up!');

            return self::SUCCESS;
        }

        // Display worktrees to be cleaned
        $this->displayWorktreeList($worktrees, $cleanupAfterDays, $all);

        // Calculate total size
        $totalSize = $this->calculateTotalSize($worktrees);
        $this->newLine();
        $this->line("Total disk space: ~{$this->formatBytes($totalSize)}");
        $this->newLine();

        // Dry run mode - just show and exit
        if ($dryRun) {
            $this->comment('🔍 Dry run mode - no worktrees were deleted');

            return self::SUCCESS;
        }

        // Confirm deletion unless --force is used
        if (! $force) {
            $this->warn('⚠️  This action cannot be undone.');
            if (! $this->confirm('Continue?', false)) {
                $this->comment('Cleanup cancelled.');

                return self::SUCCESS;
            }
            $this->newLine();
        }

        // Perform cleanup
        $removed = $this->cleanupWorktrees($worktrees, $worktreeManager);

        // Display results
        $this->newLine();
        $this->info("✓ Cleaned up {$removed} worktree(s)");
        $this->info("✓ Freed ~{$this->formatBytes($totalSize)} of disk space");

        return self::SUCCESS;
    }

    /**
     * Find worktrees to clean up.
     */
    protected function findWorktreesToCleanup(string $basePath, int $cleanupAfterDays, bool $all): array
    {
        $cutoffTime = time() - ($cleanupAfterDays * 86400);
        $worktrees = [];

        // Get all directories in the base path
        $directories = File::directories($basePath);

        // Get in-progress worktree paths to protect them
        $inProgressPaths = HealingAttempt::inProgress()
            ->whereNotNull('worktree_path')
            ->pluck('worktree_path')
            ->toArray();

        foreach ($directories as $dir) {
            // Only process paladin worktrees
            if (! str_starts_with(basename($dir), 'paladin-fix-')) {
                continue;
            }

            // Always protect in-progress worktrees
            if (in_array($dir, $inProgressPaths)) {
                continue;
            }

            $modifiedTime = File::lastModified($dir);
            $age = time() - $modifiedTime;
            $ageDays = floor($age / 86400);

            // Include if --all is specified or if older than threshold
            if ($all || $modifiedTime < $cutoffTime) {
                $worktrees[] = [
                    'path' => $dir,
                    'name' => basename($dir),
                    'modified_time' => $modifiedTime,
                    'age_days' => $ageDays,
                    'in_progress' => in_array($dir, $inProgressPaths),
                ];
            }
        }

        // Sort by age (oldest first)
        usort($worktrees, function ($a, $b) {
            return $a['modified_time'] <=> $b['modified_time'];
        });

        return $worktrees;
    }

    /**
     * Display list of worktrees to be cleaned.
     */
    protected function displayWorktreeList(array $worktrees, int $cleanupAfterDays, bool $all): void
    {
        $count = count($worktrees);
        $threshold = $all ? 'all' : "older than {$cleanupAfterDays} days";

        $this->info("Found {$count} worktree(s) to clean up ({$threshold}):");
        $this->newLine();

        foreach ($worktrees as $worktree) {
            $ageSuffix = $worktree['age_days'] === 1 ? 'day' : 'days';
            $warning = $worktree['in_progress'] ? ' ⚠️  IN PROGRESS' : '';
            $this->line("  • {$worktree['name']} ({$worktree['age_days']} {$ageSuffix} old){$warning}");
        }
    }

    /**
     * Calculate total size of worktrees.
     */
    protected function calculateTotalSize(array $worktrees): int
    {
        $totalSize = 0;

        foreach ($worktrees as $worktree) {
            $totalSize += $this->getDirectorySize($worktree['path']);
        }

        return $totalSize;
    }

    /**
     * Get size of a directory recursively.
     */
    protected function getDirectorySize(string $path): int
    {
        $size = 0;

        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CATCH_GET_CHILD
            );

            foreach ($files as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                }
            }
        } catch (\Exception $e) {
            // If we can't read the directory, estimate based on typical worktree size
            return 50 * 1024 * 1024; // 50 MB estimate
        }

        return $size;
    }

    /**
     * Format bytes to human-readable format.
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / pow(1024, $power), 2).' '.$units[$power];
    }

    /**
     * Clean up worktrees.
     */
    protected function cleanupWorktrees(array $worktrees, WorktreeManager $worktreeManager): int
    {
        $removed = 0;

        foreach ($worktrees as $worktree) {
            try {
                if ($worktreeManager->remove($worktree['path'])) {
                    $removed++;
                    $this->comment("  • Removed {$worktree['name']}");
                } else {
                    $this->warn("  • Failed to remove {$worktree['name']}");
                }
            } catch (\Exception $e) {
                $this->error("  • Error removing {$worktree['name']}: {$e->getMessage()}");
            }
        }

        return $removed;
    }

    /**
     * Get the absolute base path for worktrees.
     */
    protected function getBasePath(WorktreeManager $worktreeManager): string
    {
        return $worktreeManager->getBasePath();
    }
}
