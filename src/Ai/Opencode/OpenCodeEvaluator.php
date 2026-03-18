<?php

namespace Kekser\LaravelPaladin\Ai\Opencode;

use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Ai\Opencode\Agents\IssueAnalyzer;
use Kekser\LaravelPaladin\Ai\Opencode\Agents\PromptGenerator;
use Kekser\LaravelPaladin\Contracts\IssueEvaluator;
use Kekser\LaravelPaladin\Services\OpenCodeRunner;

class OpenCodeEvaluator implements IssueEvaluator
{
    protected OpenCodeRunner $runner;

    protected ?IssueAnalyzer $analyzer = null;

    protected ?PromptGenerator $promptGenerator = null;

    public function __construct()
    {
        $this->runner = new OpenCodeRunner;
    }

    /**
     * Get or create the IssueAnalyzer instance.
     */
    protected function getAnalyzer(): IssueAnalyzer
    {
        if ($this->analyzer === null) {
            $this->analyzer = new IssueAnalyzer;
        }

        return $this->analyzer;
    }

    /**
     * Get or create the PromptGenerator instance.
     */
    protected function getPromptGenerator(): PromptGenerator
    {
        if ($this->promptGenerator === null) {
            $this->promptGenerator = new PromptGenerator;
        }

        return $this->promptGenerator;
    }

    /**
     * Analyze log entries by running OpenCode with a structured analysis prompt.
     */
    public function analyzeIssues(array $logEntries): array
    {
        Log::info('[Paladin] Analyzing issues with OpenCode evaluator');

        $analyzer = $this->getAnalyzer();

        return $analyzer->analyze($logEntries);
    }

    /**
     * Generate a fix prompt by running OpenCode with a prompt generation request.
     */
    public function generatePrompt(array $issue, ?string $testFailureOutput = null): string
    {
        Log::info('[Paladin] Generating prompt with OpenCode evaluator', [
            'issue_id' => $issue['id'] ?? null,
        ]);

        $generator = $this->getPromptGenerator();

        return $generator->generate($issue, $testFailureOutput);
    }

    /**
     * Get validation errors for OpenCode configuration.
     */
    public function getConfigurationErrors(): array
    {
        $errors = [];

        if (! $this->runner->isAvailable()) {
            $errors[] = 'OpenCode binary is not available. Ensure OpenCode is installed and accessible in your PATH, or set PALADIN_OPENCODE_BINARY_PATH in your .env file.';
        }

        return $errors;
    }

    /**
     * Check if OpenCode is available and properly configured.
     */
    public function isConfigured(): bool
    {
        return empty($this->getConfigurationErrors());
    }
}
