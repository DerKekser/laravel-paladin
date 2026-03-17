<?php

namespace Kekser\LaravelPaladin\Ai\LaravelAi\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kekser\LaravelPaladin\Ai\AiProviderRetryHandler;
use Kekser\LaravelPaladin\Ai\Concerns\InteractsWithLogEntries;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

class IssueAnalyzer implements Agent, HasStructuredOutput
{
    use InteractsWithLogEntries, Promptable;

    protected array $logEntries = [];

    protected ?Lab $provider = null;

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
You are an expert Laravel application debugger and error analyzer. Your task is to analyze log entries and extract structured information about errors and issues.

For each log entry provided, you should:
1. Identify the error type (exception class, PHP error, database error, HTTP error, etc.)
2. Categorize the severity (critical, high, medium, low) based on the error level and impact
3. Extract the error message in a clear, human-readable format
4. Identify affected files from the stack trace
5. Provide a brief suggestion for how to fix the issue

Guidelines for severity:
- critical: Application crashes, database connection failures, critical service outages
- high: Uncaught exceptions, major feature failures, security issues
- medium: Caught exceptions with fallback, deprecation warnings affecting functionality
- low: Minor warnings, informational messages

Return a structured array of issues. If multiple log entries describe the same issue (same exception at same location), combine them into one issue.
INSTRUCTIONS;
    }

    /**
     * Get the agent's structured output schema definition.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'issues' => $schema->array()->items($schema->object([
                'id' => $schema->string()->description('Unique identifier for this issue (hash of exception type + file + line)')->required(),
                'type' => $schema->string()->description('Error type (e.g., ErrorException, QueryException, etc.)')->required(),
                'severity' => $schema->string()->enum(['critical', 'high', 'medium', 'low'])->required(),
                'title' => $schema->string()->description('Short, descriptive title for the issue')->required(),
                'message' => $schema->string()->description('Full error message')->required(),
                'stack_trace' => $schema->string()->description('Stack trace from the error log'),
                'affected_files' => $schema->array()->items($schema->string())->description('List of files mentioned in the stack trace'),
                'suggested_fix' => $schema->string()->description('Brief suggestion on how to fix this issue')->required(),
                'log_level' => $schema->string()->description('Original log level (error, critical, etc.)')->required(),
            ]))->required(),
        ];
    }

    /**
     * Analyze log entries and return structured issues.
     */
    public function analyze(array $logEntries): array
    {
        $this->logEntries = $logEntries;

        $prompt = "Analyze the following Laravel log entries and extract structured issue information:\n\n";
        $prompt .= $this->formatLogEntries($logEntries);

        $response = app(AiProviderRetryHandler::class)->executeWithRetry(
            callable: fn () => $this->prompt($prompt),
            context: [
                'agent' => 'IssueAnalyzer',
                'operation' => 'analyze_logs',
                'log_entries_count' => count($logEntries),
                'provider' => $this->provider->value ?? 'unknown',
                'model' => $this->getModel(),
            ]
        );

        return $response['issues'] ?? [];
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
