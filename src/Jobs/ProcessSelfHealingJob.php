<?php

namespace Kekser\LaravelPaladin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Ai\AgentFactory;
use Kekser\LaravelPaladin\Models\HealingAttempt;
use Kekser\LaravelPaladin\Services\LogScanner;
use Kekser\LaravelPaladin\Services\OpenCodeInstaller;
use Kekser\LaravelPaladin\Services\OpenCodeRunner;
use Kekser\LaravelPaladin\Services\PullRequestManager;
use Kekser\LaravelPaladin\Services\TestRunner;
use Kekser\LaravelPaladin\Services\WorktreeManager;

class ProcessSelfHealingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $specificIssues;

    /**
     * Create a new job instance.
     */
    public function __construct(array $specificIssues = [])
    {
        $this->specificIssues = $specificIssues;

        // Set queue connection from config
        $connection = config('paladin.queue.connection');
        $queue = config('paladin.queue.queue', 'default');

        if ($connection) {
            $this->onConnection($connection);
        }

        $this->onQueue($queue);
    }

    /**
     * Execute the self-healing job.
     */
    public function handle(): void
    {
        Log::info('[Paladin] Starting self-healing process');

        try {
            // Step 1: Ensure OpenCode is installed
            $this->ensureOpenCodeInstalled();

            // Step 2: Scan logs for issues
            $logEntries = $this->scanLogs();

            if (empty($logEntries)) {
                Log::info('[Paladin] No new log entries found');
                return;
            }

            // Step 3: Analyze issues with AI
            $issues = $this->analyzeIssues($logEntries);

            if (empty($issues)) {
                Log::info('[Paladin] No actionable issues found');
                return;
            }

            // Step 4: Process each issue
            $this->processIssues($issues);

            Log::info('[Paladin] Self-healing process completed');
        } catch (\Exception $e) {
            Log::error('[Paladin] Self-healing process failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Ensure OpenCode is installed.
     */
    protected function ensureOpenCodeInstalled(): void
    {
        $installer = new OpenCodeInstaller();

        if (!$installer->isInstalled()) {
            Log::info('[Paladin] OpenCode not installed, attempting installation');
            $installer->ensureInstalled();
        } else {
            $version = $installer->getVersion();
            Log::info('[Paladin] OpenCode is installed', ['version' => $version]);
        }
    }

    /**
     * Scan logs for new entries.
     */
    protected function scanLogs(): array
    {
        Log::info('[Paladin] Scanning logs for new entries');

        $scanner = new LogScanner();
        $entries = $scanner->scan();

        Log::info('[Paladin] Found log entries', ['count' => count($entries)]);

        return $entries;
    }

    /**
     * Analyze log entries to extract issues.
     */
    protected function analyzeIssues(array $logEntries): array
    {
        Log::info('[Paladin] Analyzing issues with AI');

        $factory = app(AgentFactory::class);
        $analyzer = $factory->createIssueAnalyzer();
        $issues = $analyzer->analyze($logEntries);

        // Sort by severity
        $severityOrder = ['critical' => 1, 'high' => 2, 'medium' => 3, 'low' => 4];
        usort($issues, function ($a, $b) use ($severityOrder) {
            $aSeverity = $severityOrder[$a['severity']] ?? 999;
            $bSeverity = $severityOrder[$b['severity']] ?? 999;
            return $aSeverity <=> $bSeverity;
        });

        // Limit to max issues per run
        $maxIssues = config('paladin.issues.max_per_run', 5);
        $issues = array_slice($issues, 0, $maxIssues);

        Log::info('[Paladin] Issues analyzed and prioritized', [
            'total' => count($issues),
            'processing' => count($issues),
        ]);

        return $issues;
    }

    /**
     * Process each issue by attempting to fix it.
     */
    protected function processIssues(array $issues): void
    {
        foreach ($issues as $issue) {
            try {
                $this->processIssue($issue);
            } catch (\Exception $e) {
                Log::error('[Paladin] Failed to process issue', [
                    'issue_id' => $issue['id'],
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Process a single issue.
     */
    protected function processIssue(array $issue): void
    {
        Log::info('[Paladin] Processing issue', [
            'id' => $issue['id'],
            'type' => $issue['type'],
            'severity' => $issue['severity'],
        ]);

        $maxAttempts = config('paladin.testing.max_fix_attempts', 3);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $healingAttempt = $this->createHealingAttempt($issue, $attempt);

                if ($this->attemptFix($healingAttempt, $issue, $attempt, $maxAttempts)) {
                    Log::info('[Paladin] Issue fixed successfully', [
                        'issue_id' => $issue['id'],
                        'attempt' => $attempt,
                    ]);
                    return;
                }

                if ($attempt < $maxAttempts) {
                    Log::info('[Paladin] Fix attempt failed, will retry', [
                        'issue_id' => $issue['id'],
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error('[Paladin] Fix attempt error', [
                    'issue_id' => $issue['id'],
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if (isset($healingAttempt)) {
                    $healingAttempt->markAsFailed($e->getMessage());
                }
            }
        }

        Log::warning('[Paladin] All fix attempts exhausted', [
            'issue_id' => $issue['id'],
            'attempts' => $maxAttempts,
        ]);
    }

    /**
     * Create a healing attempt record.
     */
    protected function createHealingAttempt(array $issue, int $attemptNumber): HealingAttempt
    {
        return HealingAttempt::create([
            'issue_id' => $issue['id'],
            'issue_type' => $issue['type'],
            'severity' => $issue['severity'],
            'message' => $issue['message'],
            'stack_trace' => $issue['stack_trace'] ?? null,
            'affected_files' => $issue['affected_files'] ?? [],
            'attempt_number' => $attemptNumber,
            'status' => 'pending',
        ]);
    }

    /**
     * Attempt to fix an issue.
     */
    protected function attemptFix(
        HealingAttempt $healingAttempt,
        array $issue,
        int $attemptNumber,
        int $maxAttempts
    ): bool {
        $healingAttempt->markAsInProgress();

        $worktreeManager = new WorktreeManager();
        $worktree = null;

        try {
            // Create worktree
            $worktree = $worktreeManager->create($issue['id']);
            $healingAttempt->update(['worktree_path' => $worktree['path']]);

            // Generate prompt for OpenCode
            // For retry attempts, fetch the previous attempt's test output
            $testFailureOutput = null;
            if ($attemptNumber > 1) {
                $previousAttempt = HealingAttempt::where('issue_id', $issue['id'])
                    ->where('attempt_number', $attemptNumber - 1)
                    ->first();
                $testFailureOutput = $previousAttempt?->test_output;
            }
            
            $factory = app(AgentFactory::class);
            $promptGenerator = $factory->createPromptGenerator($issue, $testFailureOutput);
            $prompt = $promptGenerator->generate();

            $healingAttempt->update(['opencode_prompt' => $prompt]);

            // Run OpenCode
            $opencodeRunner = new OpenCodeRunner();
            $opencodeResult = $opencodeRunner->run($prompt, $worktree['path']);

            $healingAttempt->update(['opencode_output' => $opencodeResult['output']]);

            if (!$opencodeResult['success']) {
                $healingAttempt->markAsFailed('OpenCode execution failed');
                return false;
            }

            // Run tests
            $testRunner = new TestRunner();
            $testResult = $testRunner->run($worktree['path']);

            $healingAttempt->update(['test_output' => $testResult['output']]);

            if (!$testResult['passed']) {
                $healingAttempt->markAsFailed('Tests failed after fix');
                return false;
            }

            // Tests passed! Commit and create PR
            $success = $this->commitAndCreatePR($healingAttempt, $issue, $worktree['path'], $attemptNumber, $maxAttempts);

            if ($success) {
                // Cleanup worktree if configured
                if (config('paladin.worktree.cleanup_after_success', true)) {
                    $worktreeManager->remove($worktree['path']);
                }
            }

            return $success;
        } catch (\Exception $e) {
            if ($worktree && $worktreeManager->exists($worktree['path'])) {
                // Keep worktree for manual inspection on failure
                Log::info('[Paladin] Keeping worktree for inspection', [
                    'path' => $worktree['path'],
                ]);
            }

            throw $e;
        }
    }

    /**
     * Commit changes and create a pull request.
     */
    protected function commitAndCreatePR(
        HealingAttempt $healingAttempt,
        array $issue,
        string $worktreePath,
        int $attemptNumber,
        int $maxAttempts
    ): bool {
        // Create branch name
        $branchPrefix = config('paladin.git.branch_prefix', 'paladin/fix');
        $branchName = "{$branchPrefix}-" . substr($issue['id'], 0, 8);

        // Create and checkout branch - properly escape all arguments
        $commands = [
            sprintf('cd %s', escapeshellarg($worktreePath)),
            sprintf('git checkout -b %s', escapeshellarg($branchName)),
            'git add .',
        ];

        exec(implode(' && ', $commands), $output, $returnCode);

        if ($returnCode !== 0) {
            Log::error('[Paladin] Failed to create branch', ['output' => implode("\n", $output)]);
            return false;
        }

        // Generate commit message
        $commitMessage = $this->generateCommitMessage($issue, $attemptNumber, $maxAttempts);

        // Commit
        $commitCommand = sprintf(
            "cd %s && git commit -m %s",
            escapeshellarg($worktreePath),
            escapeshellarg($commitMessage)
        );

        exec($commitCommand, $output, $returnCode);

        if ($returnCode !== 0) {
            Log::error('[Paladin] Failed to commit changes', ['output' => implode("\n", $output)]);
            return false;
        }

        // Push branch
        $pushCommand = sprintf(
            'cd %s && git push origin %s',
            escapeshellarg($worktreePath),
            escapeshellarg($branchName)
        );
        exec($pushCommand, $output, $returnCode);

        if ($returnCode !== 0) {
            Log::error('[Paladin] Failed to push branch', ['output' => implode("\n", $output)]);
            return false;
        }

        $healingAttempt->update(['branch_name' => $branchName]);

        // Create PR
        $prManager = new PullRequestManager();
        $prTitle = $this->generatePRTitle($issue);
        $prBody = $this->generatePRBody($issue, $attemptNumber, $maxAttempts);

        $prUrl = $prManager->createPullRequest($branchName, $prTitle, $prBody);

        if ($prUrl) {
            $healingAttempt->markAsFixed($prUrl);
            return true;
        }

        $healingAttempt->markAsFailed('Failed to create pull request');
        return false;
    }

    /**
     * Generate commit message from template.
     */
    protected function generateCommitMessage(array $issue, int $attemptNumber, int $maxAttempts): string
    {
        $template = config('paladin.git.commit_message_template');

        return strtr($template, [
            '{issue_title}' => $issue['title'],
            '{issue_description}' => $issue['message'],
            '{severity}' => $issue['severity'],
            '{attempt_number}' => $attemptNumber,
            '{max_attempts}' => $maxAttempts,
        ]);
    }

    /**
     * Generate PR title from template.
     */
    protected function generatePRTitle(array $issue): string
    {
        $template = config('paladin.git.pr_title_template');

        return strtr($template, [
            '{issue_title}' => $issue['title'],
        ]);
    }

    /**
     * Generate PR body from template.
     */
    protected function generatePRBody(array $issue, int $attemptNumber, int $maxAttempts): string
    {
        $template = config('paladin.git.pr_body_template');

        return strtr($template, [
            '{issue_type}' => $issue['type'],
            '{severity}' => strtoupper($issue['severity']),
            '{affected_files}' => implode(', ', $issue['affected_files'] ?? []),
            '{issue_description}' => $issue['message'],
            '{stack_trace}' => $issue['stack_trace'] ?? 'N/A',
            '{attempt_number}' => $attemptNumber,
            '{max_attempts}' => $maxAttempts,
        ]);
    }
}
