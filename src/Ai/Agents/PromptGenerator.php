<?php

namespace Kekser\LaravelPaladin\Ai\Agents;

use Kekser\LaravelPaladin\Ai\AiProviderRetryHandler;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

class PromptGenerator implements Agent
{
    use Promptable;

    protected ?Lab $provider = null;

    public function __construct(
        protected array $issue,
        protected ?string $testFailureOutput = null
    ) {}

    /**
     * Set the AI provider to use for this agent.
     */
    public function setProvider(Lab $provider): void
    {
        $this->provider = $provider;
    }

    /**
     * Get the AI provider for this agent (used by Laravel AI).
     */
    public function provider(): Lab
    {
        return $this->provider;
    }

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        return <<<'INSTRUCTIONS'
You are an expert at creating effective prompts for OpenCode, an AI coding agent. Your task is to generate a clear, actionable prompt that will help OpenCode fix a specific issue in a Laravel application.

When creating a prompt for OpenCode, you should:
1. Clearly describe the issue that needs to be fixed
2. Include the error message and relevant stack trace information
3. Specify the affected files if known
4. Provide context about what the code should do
5. Be specific and actionable
6. Keep the prompt concise but complete

The prompt should be written in a direct, imperative style as if you're giving instructions to a skilled developer.

If test failure output is provided, incorporate it into the prompt to help OpenCode understand what went wrong and how to fix it.
INSTRUCTIONS;
    }

    /**
     * Generate a prompt for OpenCode based on the issue.
     */
    public function generate(): string
    {
        $basePrompt = $this->buildBasePrompt();

        if ($this->testFailureOutput) {
            $basePrompt .= "\n\n".$this->buildTestFailureContext();
        }

        $response = app(AiProviderRetryHandler::class)->executeWithRetry(
            callable: fn () => $this->prompt($basePrompt),
            context: [
                'agent' => 'PromptGenerator',
                'operation' => 'generate_prompt',
                'issue_id' => $this->issue['id'] ?? null,
                'issue_type' => $this->issue['type'] ?? 'unknown',
                'has_test_failure' => ! empty($this->testFailureOutput),
                'provider' => $this->provider->value ?? 'unknown',
                'model' => $this->getModel(),
            ]
        );

        return (string) $response;
    }

    /**
     * Build the base prompt from the issue details.
     */
    protected function buildBasePrompt(): string
    {
        $issue = $this->issue;

        $context = "Create an effective OpenCode prompt to fix the following Laravel application issue:\n\n";
        $context .= "**Issue Type**: {$issue['type']}\n";
        $context .= "**Severity**: {$issue['severity']}\n";
        $context .= "**Title**: {$issue['title']}\n\n";
        $context .= "**Error Message**:\n{$issue['message']}\n\n";

        if (! empty($issue['affected_files'])) {
            $context .= "**Affected Files**:\n";
            foreach ($issue['affected_files'] as $file) {
                $context .= "- {$file}\n";
            }
            $context .= "\n";
        }

        if (! empty($issue['suggested_fix'])) {
            $context .= "**Suggested Fix**: {$issue['suggested_fix']}\n\n";
        }

        $context .= 'Generate a clear, actionable prompt that OpenCode can use to fix this issue. The prompt should be specific and include all necessary context.';

        return $context;
    }

    /**
     * Build additional context from test failures.
     */
    protected function buildTestFailureContext(): string
    {
        $context = "**Previous Fix Attempt Failed**\n\n";
        $context .= "The previous fix attempt resulted in test failures. Here is the test output:\n\n";
        $context .= "```\n{$this->testFailureOutput}\n```\n\n";
        $context .= 'Update the prompt to address these test failures. The fix should not only resolve the original error but also ensure all tests pass.';

        return $context;
    }

    /**
     * Get model configuration from package config.
     */
    protected function getModel(): string
    {
        return config('paladin.ai.model', 'gemini-2.0-flash-exp');
    }

    /**
     * Get temperature configuration from package config.
     */
    protected function temperature(): float
    {
        return config('paladin.ai.temperature', 0.7);
    }
}
