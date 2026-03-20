<?php

namespace Kekser\LaravelPaladin\Ai\Simple;

use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Ai\Simple\Agents\IssueAnalyzer;
use Kekser\LaravelPaladin\Ai\Simple\Agents\PromptGenerator;
use Kekser\LaravelPaladin\Contracts\IssueEvaluator;

/**
 * Simple evaluator that analyzes log entries without using AI.
 *
 * This evaluator extracts structured issue data from log entries using
 * pattern matching and heuristics, then generates generic fix prompts.
 * It requires no external AI services or credentials.
 */
class SimpleEvaluator implements IssueEvaluator
{
    protected ?IssueAnalyzer $analyzer = null;

    protected ?PromptGenerator $promptGenerator = null;

    /**
     * Get or create the IssueAnalyzer instance.
     */
    protected function getAnalyzer(): IssueAnalyzer
    {
        if ($this->analyzer === null) {
            $this->analyzer = app(IssueAnalyzer::class);
        }

        return $this->analyzer;
    }

    /**
     * Get or create the PromptGenerator instance.
     */
    protected function getPromptGenerator(): PromptGenerator
    {
        if ($this->promptGenerator === null) {
            $this->promptGenerator = app(PromptGenerator::class);
        }

        return $this->promptGenerator;
    }

    /**
     * Analyze log entries and return structured issue information.
     *
     * This method extracts error types, severity, affected files, and
     * suggested fixes from log entries using pattern matching without AI.
     *
     * @param  array  $logEntries  Array of log entry data from LogScanner
     * @return array Array of structured issue data
     */
    public function analyzeIssues(array $logEntries): array
    {
        Log::info('[Paladin] Analyzing issues with Simple evaluator (no AI)', [
            'entry_count' => count($logEntries),
        ]);

        $analyzer = $this->getAnalyzer();

        return $analyzer->analyze($logEntries);
    }

    /**
     * Generate a prompt for fixing a specific issue.
     *
     * This method creates a comprehensive fix prompt using the issue
     * data without making any AI calls.
     *
     * @param  array  $issue  Structured issue data
     * @param  string|null  $testFailureOutput  Output from a previous failed test run (for retry attempts)
     * @return string The generated prompt
     */
    public function generatePrompt(array $issue, ?string $testFailureOutput = null): string
    {
        Log::info('[Paladin] Generating prompt with Simple evaluator (no AI)', [
            'issue_id' => $issue['id'] ?? null,
            'issue_type' => $issue['type'] ?? 'unknown',
        ]);

        $generator = $this->getPromptGenerator();

        return $generator->generate($issue, $testFailureOutput);
    }

    /**
     * Check if this evaluator is properly configured and ready to use.
     *
     * The Simple evaluator requires no external configuration and is
     * always ready to use.
     *
     * @return bool Always returns true
     */
    public function isConfigured(): bool
    {
        return true;
    }

    /**
     * Get validation errors for this evaluator's configuration.
     *
     * The Simple evaluator has no external dependencies and will
     * never return configuration errors.
     *
     * @return array Always returns an empty array
     */
    public function getConfigurationErrors(): array
    {
        return [];
    }
}
