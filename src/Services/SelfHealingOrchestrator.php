<?php

namespace Kekser\LaravelPaladin\Services;

use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Ai\EvaluatorFactory;
use Kekser\LaravelPaladin\Contracts\IssueEvaluator;
use Kekser\LaravelPaladin\Models\HealingAttempt;
use Kekser\LaravelPaladin\Pr\PullRequestManager;

class SelfHealingOrchestrator
{
    protected ?IssueEvaluator $evaluator = null;

    public function __construct(
        protected IssuePrioritizer $issuePrioritizer,
        protected TemplateGenerator $templateGenerator,
        protected FileBoundaryValidator $fileBoundaryValidator,
        protected WorktreeManager $worktreeManager,
        protected WorktreeSetup $worktreeSetup,
        protected OpenCodeRunner $openCodeRunner,
        protected TestRunner $testRunner,
        protected GitService $gitService,
        protected PullRequestManager $pullRequestManager,
    ) {}

    /**
     * Process multiple issues.
     *
     * @param  array  $issues  Array of issues to process
     * @param  array  $specificIssues  Optional specific issue IDs to filter by
     */
    public function processIssues(array $issues, array $specificIssues = []): void
    {
        // Filter to specific issues if provided
        if (! empty($specificIssues)) {
            $issues = array_filter($issues, function ($issue) use ($specificIssues) {
                return in_array($issue['id'], $specificIssues);
            });
        }

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
     *
     * @param  array  $issue  Issue data
     */
    protected function processIssue(array $issue): void
    {
        Log::info('[Paladin] Processing issue', [
            'id' => $issue['id'],
            'type' => $issue['type'],
            'severity' => $issue['severity'],
        ]);

        // Validate file boundaries before attempting fix
        $validation = $this->fileBoundaryValidator->analyzeIssue($issue['affected_files'] ?? []);

        if (! $validation['is_fixable']) {
            $this->createSkippedAttempt($issue, $validation);

            return;
        }

        // Log that we're proceeding with fixable issue
        if (! empty($validation['external_files'])) {
            Log::info('[Paladin] Issue is fixable - proceeding with fix', [
                'issue_id' => $issue['id'],
                'internal_files_count' => count($validation['internal_files']),
                'external_files_count' => count($validation['external_files']),
                'internal_files' => $validation['internal_files'],
            ]);
        }

        $this->attemptFixWithRetries($issue);
    }

    /**
     * Create a skipped healing attempt record.
     *
     * @param  array  $issue  Issue data
     * @param  array  $validation  Validation result from FileBoundaryValidator
     */
    protected function createSkippedAttempt(array $issue, array $validation): HealingAttempt
    {
        $healingAttempt = HealingAttempt::create([
            'issue_id' => $issue['id'],
            'issue_type' => $issue['type'],
            'severity' => $issue['severity'],
            'message' => $issue['message'],
            'stack_trace' => $issue['stack_trace'] ?? null,
            'affected_files' => $issue['affected_files'] ?? [],
            'attempt_number' => 1,
            'status' => 'skipped',
            'error_message' => $validation['reason'],
        ]);

        Log::warning('[Paladin] Issue skipped - root cause outside project', [
            'issue_id' => $issue['id'],
            'issue_type' => $issue['type'],
            'severity' => $issue['severity'],
            'reason' => $validation['reason'],
            'external_files' => $validation['external_files'],
            'affected_files_count' => count($issue['affected_files'] ?? []),
        ]);

        return $healingAttempt;
    }

    /**
     * Attempt to fix an issue with multiple retries.
     *
     * @param  array  $issue  Issue data
     */
    protected function attemptFixWithRetries(array $issue): void
    {
        $maxAttempts = config('paladin.testing.max_fix_attempts', 3);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $healingAttempt = null;

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

                if ($healingAttempt) {
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
     *
     * @param  array  $issue  Issue data
     * @param  int  $attemptNumber  Attempt number
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
     *
     * @param  HealingAttempt  $healingAttempt  The healing attempt record
     * @param  array  $issue  Issue data
     * @param  int  $attemptNumber  Current attempt number
     * @param  int  $maxAttempts  Maximum number of attempts
     * @return bool Whether the fix was successful
     */
    protected function attemptFix(
        HealingAttempt $healingAttempt,
        array $issue,
        int $attemptNumber,
        int $maxAttempts
    ): bool {
        $healingAttempt->markAsInProgress();

        $worktree = null;

        try {
            // Create worktree
            $worktree = $this->worktreeManager->create($issue['id']);
            $healingAttempt->update(['worktree_path' => $worktree['path']]);

            // Setup worktree
            if (config('paladin.worktree.setup.enabled', true)) {
                if (! $this->setupWorktree($healingAttempt, $worktree['path'])) {
                    return false;
                }
            }

            // Run OpenCode
            if (! $this->runOpenCode($healingAttempt, $issue, $worktree['path'], $attemptNumber)) {
                return false;
            }

            // Run tests (unless skipped)
            if (! $this->runTests($healingAttempt, $worktree['path'])) {
                return false;
            }

            // Commit and create PR
            $success = $this->commitAndCreatePR($healingAttempt, $issue, $worktree['path'], $attemptNumber, $maxAttempts);

            // Cleanup worktree on success
            if ($success && config('paladin.worktree.cleanup_after_success', true)) {
                $this->worktreeManager->remove($worktree['path']);
            }

            return $success;
        } catch (\Exception $e) {
            if ($worktree && $this->worktreeManager->exists($worktree['path'])) {
                Log::info('[Paladin] Keeping worktree for inspection', [
                    'path' => $worktree['path'],
                ]);
            }

            throw $e;
        }
    }

    /**
     * Setup the worktree environment.
     *
     * @param  HealingAttempt  $healingAttempt  The healing attempt record
     * @param  string  $worktreePath  Path to the worktree
     * @return bool Whether setup was successful
     */
    protected function setupWorktree(HealingAttempt $healingAttempt, string $worktreePath): bool
    {
        $setupSuccess = $this->worktreeSetup->setup($worktreePath);

        if (! $setupSuccess) {
            $healingAttempt->markAsFailed('Worktree setup failed');

            if ($this->worktreeManager->exists($worktreePath)) {
                $this->worktreeManager->remove($worktreePath);
            }

            return false;
        }

        return true;
    }

    /**
     * Run OpenCode to generate a fix.
     *
     * @param  HealingAttempt  $healingAttempt  The healing attempt record
     * @param  array  $issue  Issue data
     * @param  string  $worktreePath  Path to the worktree
     * @param  int  $attemptNumber  Current attempt number
     * @return bool Whether OpenCode execution was successful
     */
    protected function runOpenCode(
        HealingAttempt $healingAttempt,
        array $issue,
        string $worktreePath,
        int $attemptNumber
    ): bool {
        // For retry attempts, fetch the previous attempt's test output
        $testFailureOutput = null;
        if ($attemptNumber > 1) {
            $previousAttempt = HealingAttempt::where('issue_id', $issue['id'])
                ->where('attempt_number', $attemptNumber - 1)
                ->first();
            $testFailureOutput = $previousAttempt?->test_output;
        }

        $evaluator = $this->getEvaluator();
        $prompt = $evaluator->generatePrompt($issue, $testFailureOutput);
        $healingAttempt->update(['opencode_prompt' => $prompt]);

        $opencodeResult = $this->openCodeRunner->run($prompt, $worktreePath);
        $healingAttempt->update(['opencode_output' => $opencodeResult['output']]);

        if (! $opencodeResult['success']) {
            $healingAttempt->markAsFailed('OpenCode execution failed');

            return false;
        }

        return true;
    }

    /**
     * Run tests on the fixed code.
     *
     * @param  HealingAttempt  $healingAttempt  The healing attempt record
     * @param  string  $worktreePath  Path to the worktree
     * @return bool Whether tests passed
     */
    protected function runTests(HealingAttempt $healingAttempt, string $worktreePath): bool
    {
        $skipTests = config('paladin.testing.skip_tests', false);

        if ($skipTests) {
            Log::info('[Paladin] Skipping test execution (PALADIN_SKIP_TESTS=true)');
            $healingAttempt->update([
                'test_output' => 'Tests skipped (PALADIN_SKIP_TESTS=true)',
            ]);

            return true;
        }

        $testResult = $this->testRunner->run($worktreePath);
        $healingAttempt->update(['test_output' => $testResult['output']]);

        if (! $testResult['passed']) {
            $healingAttempt->markAsFailed('Tests failed after fix');

            return false;
        }

        return true;
    }

    /**
     * Commit changes and create a pull request.
     *
     * @param  HealingAttempt  $healingAttempt  The healing attempt record
     * @param  array  $issue  Issue data
     * @param  string  $worktreePath  Path to the worktree
     * @param  int  $attemptNumber  Current attempt number
     * @param  int  $maxAttempts  Maximum number of attempts
     * @return bool Whether commit and PR creation was successful
     */
    protected function commitAndCreatePR(
        HealingAttempt $healingAttempt,
        array $issue,
        string $worktreePath,
        int $attemptNumber,
        int $maxAttempts
    ): bool {
        $branchName = $this->templateGenerator->generateBranchName($issue);

        // Create and checkout branch
        if (! $this->gitService->createBranch($worktreePath, $branchName)) {
            $healingAttempt->markAsFailed('Failed to create branch');

            return false;
        }

        // Generate commit message and commit
        $commitMessage = $this->templateGenerator->generateCommitMessage($issue, $attemptNumber, $maxAttempts);
        if (! $this->gitService->commit($worktreePath, $commitMessage)) {
            $healingAttempt->markAsFailed('Failed to commit changes');

            return false;
        }

        $healingAttempt->update(['branch_name' => $branchName]);

        // Check if remote exists
        $hasRemote = $this->gitService->hasRemote($worktreePath);

        // Push branch if remote is configured
        if (! $hasRemote) {
            Log::info('[Paladin] No remote configured, fix committed locally', [
                'branch' => $branchName,
                'worktree_path' => $worktreePath,
            ]);
            $healingAttempt->markAsFixed(null);

            return true;
        }

        if (! $this->gitService->push($worktreePath, $branchName)) {
            $healingAttempt->markAsFailed('Failed to push branch');

            return false;
        }

        // Create PR
        $prTitle = $this->templateGenerator->generatePRTitle($issue);
        $prBody = $this->templateGenerator->generatePRBody($issue, $attemptNumber, $maxAttempts);
        $prUrl = $this->pullRequestManager->createPullRequest($branchName, $prTitle, $prBody);

        if (! $prUrl) {
            $healingAttempt->markAsFailed('Failed to create pull request');

            return false;
        }

        $healingAttempt->markAsFixed($prUrl);

        return true;
    }

    /**
     * Get the configured issue evaluator instance.
     */
    protected function getEvaluator(): IssueEvaluator
    {
        if ($this->evaluator === null) {
            $this->evaluator = app(EvaluatorFactory::class)->create();
        }

        return $this->evaluator;
    }
}
