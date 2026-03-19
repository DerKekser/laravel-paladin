<?php

namespace Kekser\LaravelPaladin\Commands;

use Illuminate\Console\Command;
use Kekser\LaravelPaladin\Models\HealingAttempt;

class StatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'paladin:status
                            {--status= : Filter by status (pending, in_progress, fixed, failed)}
                            {--limit=10 : Number of recent attempts to display}
                            {--details : Show detailed information including stack traces}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Display current status of healing attempts';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('🛡️  Laravel Paladin - Healing Status');
        $this->newLine();

        // Get filter options
        $statusFilter = $this->option('status');
        $limit = (int) $this->option('limit');
        $verbose = $this->option('details');

        // Validate status filter
        if ($statusFilter && ! in_array($statusFilter, ['pending', 'in_progress', 'fixed', 'failed'])) {
            $this->error('Invalid status filter. Use: pending, in_progress, fixed, or failed');

            return self::FAILURE;
        }

        // Display overall statistics (unless filtered)
        if (! $statusFilter) {
            $this->displayOverallStatistics();
            $this->newLine();
        }

        // Display recent attempts
        $this->displayRecentAttempts($statusFilter, $limit, $verbose);

        return self::SUCCESS;
    }

    /**
     * Display overall statistics of healing attempts.
     */
    protected function displayOverallStatistics(): void
    {
        $total = HealingAttempt::count();
        $fixed = HealingAttempt::fixed()->count();
        $failed = HealingAttempt::failed()->count();
        $inProgress = HealingAttempt::inProgress()->count();
        $pending = HealingAttempt::pending()->count();

        $this->info('Overall Statistics:');
        $this->line("  • Total Attempts: {$total}");
        $this->line("  • ✓ Fixed: {$fixed}");
        $this->line("  • ✗ Failed: {$failed}");
        $this->line("  • ⏳ In Progress: {$inProgress}");
        $this->line("  • ⏸ Pending: {$pending}");
    }

    /**
     * Display recent healing attempts.
     */
    protected function displayRecentAttempts(?string $statusFilter, int $limit, bool $verbose): void
    {
        // Build query
        $query = HealingAttempt::query();

        if ($statusFilter) {
            switch ($statusFilter) {
                case 'pending':
                    $query->pending();
                    break;
                case 'in_progress':
                    $query->inProgress();
                    break;
                case 'fixed':
                    $query->fixed();
                    break;
                case 'failed':
                    $query->failed();
                    break;
            }
        }

        $attempts = $query->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        if ($attempts->isEmpty()) {
            $this->comment('No healing attempts found.');

            return;
        }

        $title = $statusFilter
            ? 'Recent '.ucfirst(str_replace('_', ' ', $statusFilter)).' Attempts:'
            : 'Recent Attempts:';

        $this->info($title);
        $this->newLine();

        // Display table header
        if ($verbose) {
            $this->displayVerboseAttempts($attempts);
        } else {
            $this->displayCompactAttempts($attempts);
        }
    }

    /**
     * Display attempts in compact table format.
     */
    protected function displayCompactAttempts($attempts): void
    {
        $headers = ['ID', 'Status', 'Issue Type', 'Severity', 'Created', 'PR/Details'];
        $rows = [];

        foreach ($attempts as $attempt) {
            $statusIcon = $this->getStatusIcon($attempt->status);
            $created = $attempt->created_at->diffForHumans();
            $details = $this->getAttemptDetails($attempt);

            $rows[] = [
                "#{$attempt->id}",
                "{$statusIcon} {$attempt->status}",
                $attempt->issue_type ?? 'N/A',
                $attempt->severity ?? 'N/A',
                $created,
                $details,
            ];
        }

        $this->table($headers, $rows);
    }

    /**
     * Display attempts with verbose information.
     */
    protected function displayVerboseAttempts($attempts): void
    {
        foreach ($attempts as $index => $attempt) {
            if ($index > 0) {
                $this->newLine();
                $this->line(str_repeat('─', 80));
                $this->newLine();
            }

            $statusIcon = $this->getStatusIcon($attempt->status);

            $this->line("<comment>ID:</comment> #{$attempt->id}");
            $this->line("<comment>Status:</comment> {$statusIcon} {$attempt->status}");
            $this->line("<comment>Issue Type:</comment> {$attempt->issue_type}");
            $this->line("<comment>Severity:</comment> {$attempt->severity}");
            $this->line("<comment>Created:</comment> {$attempt->created_at->format('Y-m-d H:i:s')} ({$attempt->created_at->diffForHumans()})");

            if ($attempt->message) {
                $this->line("<comment>Message:</comment> {$attempt->message}");
            }

            if ($attempt->affected_files) {
                $files = is_array($attempt->affected_files)
                    ? implode(', ', $attempt->affected_files)
                    : $attempt->affected_files;
                $this->line("<comment>Affected Files:</comment> {$files}");
            }

            if ($attempt->worktree_path) {
                $this->line("<comment>Worktree Path:</comment> {$attempt->worktree_path}");
            }

            if ($attempt->branch_name) {
                $this->line("<comment>Branch Name:</comment> {$attempt->branch_name}");
            }

            if ($attempt->pr_url) {
                $this->line("<comment>PR URL:</comment> {$attempt->pr_url}");
            }

            if ($attempt->error_message) {
                $this->line("<comment>Error:</comment> {$attempt->error_message}");
            }

            if ($attempt->attempt_number) {
                $this->line("<comment>Attempt Number:</comment> {$attempt->attempt_number}");
            }

            if ($attempt->stack_trace) {
                $this->newLine();
                $this->line('<comment>Stack Trace:</comment>');
                $this->line($this->truncateStackTrace($attempt->stack_trace));
            }
        }
    }

    /**
     * Get status icon based on status.
     */
    protected function getStatusIcon(string $status): string
    {
        return match ($status) {
            'fixed' => '✓',
            'failed' => '✗',
            'in_progress' => '⏳',
            'pending' => '⏸',
            default => '•',
        };
    }

    /**
     * Get attempt details for compact display.
     */
    protected function getAttemptDetails(HealingAttempt $attempt): string
    {
        if ($attempt->status === 'fixed' && $attempt->pr_url) {
            return $this->truncateUrl($attempt->pr_url);
        }

        if ($attempt->status === 'in_progress' && $attempt->worktree_path) {
            return 'Working...';
        }

        if ($attempt->status === 'failed' && $attempt->error_message) {
            return $this->truncateText($attempt->error_message, 40);
        }

        if ($attempt->status === 'pending') {
            return 'Queued';
        }

        return '-';
    }

    /**
     * Truncate URL for display.
     */
    protected function truncateUrl(string $url): string
    {
        // Extract repo and PR number if it's a GitHub URL
        if (preg_match('#github\.com/([^/]+/[^/]+)/pull/(\d+)#', $url, $matches)) {
            return "{$matches[1]}#{$matches[2]}";
        }

        // Otherwise just truncate
        return $this->truncateText($url, 50);
    }

    /**
     * Truncate text to specified length.
     */
    protected function truncateText(string $text, int $length): string
    {
        if (mb_strlen($text) <= $length) {
            return $text;
        }

        return mb_substr($text, 0, $length - 3).'...';
    }

    /**
     * Truncate stack trace for display.
     */
    protected function truncateStackTrace(string $stackTrace): string
    {
        $lines = explode("\n", $stackTrace);
        $maxLines = 10;

        if (count($lines) <= $maxLines) {
            return $stackTrace;
        }

        $truncated = array_slice($lines, 0, $maxLines);
        $remaining = count($lines) - $maxLines;

        return implode("\n", $truncated)."\n... and {$remaining} more lines";
    }
}
