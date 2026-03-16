<?php

namespace Kekser\LaravelPaladin\Contracts;

interface IssueEvaluator
{
    /**
     * Analyze log entries and return structured issues.
     *
     * Each issue should contain: id, type, severity, title, message,
     * stack_trace, affected_files, suggested_fix, log_level.
     *
     * @param  array  $logEntries  Raw log entries from LogScanner
     * @return array Array of structured issue data
     */
    public function analyzeIssues(array $logEntries): array;

    /**
     * Generate a prompt for OpenCode to fix a specific issue.
     *
     * @param  array  $issue  Structured issue data
     * @param  string|null  $testFailureOutput  Output from a previous failed test run (for retry attempts)
     * @return string The generated prompt
     */
    public function generatePrompt(array $issue, ?string $testFailureOutput = null): string;

    /**
     * Check if this evaluator is properly configured and ready to use.
     */
    public function isConfigured(): bool;

    /**
     * Get validation errors for this evaluator's configuration.
     *
     * @return array List of error messages, empty if configuration is valid
     */
    public function getConfigurationErrors(): array;
}
