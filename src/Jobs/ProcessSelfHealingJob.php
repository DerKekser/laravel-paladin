<?php

namespace Kekser\LaravelPaladin\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Ai\EvaluatorFactory;
use Kekser\LaravelPaladin\Contracts\IssueEvaluator;
use Kekser\LaravelPaladin\Services\IssuePrioritizer;
use Kekser\LaravelPaladin\Services\LogScanner;
use Kekser\LaravelPaladin\Services\OpenCodeInstaller;
use Kekser\LaravelPaladin\Services\SelfHealingOrchestrator;

class ProcessSelfHealingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected array $specificIssues;

    protected ?IssueEvaluator $evaluator = null;

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
    public function handle(
        OpenCodeInstaller $installer,
        LogScanner $scanner,
        IssuePrioritizer $prioritizer,
        SelfHealingOrchestrator $orchestrator
    ): void {
        Log::info('[Paladin] Starting self-healing process');

        try {
            // Step 1: Ensure OpenCode is installed
            $this->ensureOpenCodeInstalled($installer);

            // Step 2: Scan logs for issues
            $logEntries = $this->scanLogs($scanner);

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

            // Step 4: Prioritize and limit issues
            $issues = $prioritizer->prioritize($issues);

            Log::info('[Paladin] Issues analyzed and prioritized', [
                'total' => count($issues),
                'processing' => count($issues),
            ]);

            // Step 5: Process issues through orchestrator
            $orchestrator->processIssues($issues, $this->specificIssues);

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
    protected function ensureOpenCodeInstalled(OpenCodeInstaller $installer): void
    {
        if (! $installer->isInstalled()) {
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
    protected function scanLogs(LogScanner $scanner): array
    {
        Log::info('[Paladin] Scanning logs for new entries');

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

        $evaluator = $this->getEvaluator();
        $issues = $evaluator->analyzeIssues($logEntries);

        return $issues;
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
