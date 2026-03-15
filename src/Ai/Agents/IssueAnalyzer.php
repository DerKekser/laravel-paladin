<?php

namespace Kekser\LaravelPaladin\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::Gemini)]
class IssueAnalyzer implements Agent, HasStructuredOutput
{
    use Promptable;

    protected array $logEntries = [];

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
            'issues' => $schema->array()->items([
                'id' => $schema->string()->description('Unique identifier for this issue (hash of exception type + file + line)')->required(),
                'type' => $schema->string()->description('Error type (e.g., ErrorException, QueryException, etc.)')->required(),
                'severity' => $schema->enum(['critical', 'high', 'medium', 'low'])->required(),
                'title' => $schema->string()->description('Short, descriptive title for the issue')->required(),
                'message' => $schema->string()->description('Full error message')->required(),
                'affected_files' => $schema->array()->items($schema->string())->description('List of files mentioned in the stack trace'),
                'suggested_fix' => $schema->string()->description('Brief suggestion on how to fix this issue')->required(),
                'log_level' => $schema->string()->description('Original log level (error, critical, etc.)')->required(),
            ])->required(),
        ];
    }

    /**
     * Analyze log entries and return structured issues.
     */
    public function analyze(array $logEntries): array
    {
        $this->logEntries = $logEntries;

        // Format log entries for the prompt
        $formattedEntries = array_map(function ($entry) {
            return sprintf(
                "[%s] %s: %s\n%s",
                date('Y-m-d H:i:s', $entry['timestamp']),
                strtoupper($entry['level']),
                $entry['message'],
                $entry['stack_trace'] ?? ''
            );
        }, $logEntries);

        $prompt = "Analyze the following Laravel log entries and extract structured issue information:\n\n";
        $prompt .= implode("\n\n---\n\n", $formattedEntries);

        $response = $this->prompt($prompt);

        return $response['issues'] ?? [];
    }

    /**
     * Get model configuration from package config.
     */
    protected function getModel(): string
    {
        return config('paladin.ai.model_analysis', 'gemini-2.0-flash-exp');
    }
}
