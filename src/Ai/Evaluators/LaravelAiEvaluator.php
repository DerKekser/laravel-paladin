<?php

namespace Kekser\LaravelPaladin\Ai\Evaluators;

use Kekser\LaravelPaladin\Ai\AgentFactory;
use Kekser\LaravelPaladin\Contracts\IssueEvaluator;

class LaravelAiEvaluator implements IssueEvaluator
{
    protected ?AgentFactory $factory = null;

    /**
     * Get or create the AgentFactory instance (lazy-loaded).
     */
    protected function getFactory(): AgentFactory
    {
        if ($this->factory === null) {
            $this->factory = app(AgentFactory::class);
        }

        return $this->factory;
    }

    /**
     * Analyze log entries using the Laravel AI IssueAnalyzer agent.
     */
    public function analyzeIssues(array $logEntries): array
    {
        $analyzer = $this->getFactory()->createIssueAnalyzer();

        return $analyzer->analyze($logEntries);
    }

    /**
     * Generate a prompt using the Laravel AI PromptGenerator agent.
     */
    public function generatePrompt(array $issue, ?string $testFailureOutput = null): string
    {
        $generator = $this->getFactory()->createPromptGenerator($issue, $testFailureOutput);

        return $generator->generate();
    }

    /**
     * Check if laravel-ai is properly configured.
     */
    public function isConfigured(): bool
    {
        return empty($this->getConfigurationErrors());
    }

    /**
     * Get validation errors for laravel-ai configuration.
     */
    public function getConfigurationErrors(): array
    {
        $errors = [];

        try {
            $this->getFactory();
        } catch (\Exception $e) {
            $errors[] = $e->getMessage();
        }

        return $errors;
    }
}
