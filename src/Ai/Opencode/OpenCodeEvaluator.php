<?php

namespace Kekser\LaravelPaladin\Ai\Opencode;

use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Contracts\IssueEvaluator;
use Kekser\LaravelPaladin\Services\OpenCodeRunner;
use RuntimeException;

class OpenCodeEvaluator implements IssueEvaluator
{
    protected OpenCodeRunner $runner;

    public function __construct()
    {
        $this->runner = new OpenCodeRunner;
    }

    /**
     * Analyze log entries by running OpenCode with a structured analysis prompt.
     */
    public function analyzeIssues(array $logEntries): array
    {
        Log::info('[Paladin] Analyzing issues with OpenCode evaluator');

        $prompt = $this->buildAnalysisPrompt($logEntries);

        // Run OpenCode in the project root to analyze log entries
        $workingDirectory = base_path();
        $result = $this->runner->run($prompt, $workingDirectory);

        if (! $result['success']) {
            Log::error('[Paladin] OpenCode issue analysis failed', [
                'return_code' => $result['return_code'],
                'output' => substr($result['output'], 0, 500),
            ]);

            throw new RuntimeException('OpenCode issue analysis failed: '.$result['output']);
        }

        return $this->parseAnalysisOutput($result['stdout']);
    }

    /**
     * Generate a fix prompt by running OpenCode with a prompt generation request.
     */
    public function generatePrompt(array $issue, ?string $testFailureOutput = null): string
    {
        Log::info('[Paladin] Generating prompt with OpenCode evaluator', [
            'issue_id' => $issue['id'] ?? null,
        ]);

        $prompt = $this->buildPromptGenerationPrompt($issue, $testFailureOutput);

        $workingDirectory = base_path();
        $result = $this->runner->run($prompt, $workingDirectory);

        if (! $result['success']) {
            Log::error('[Paladin] OpenCode prompt generation failed', [
                'return_code' => $result['return_code'],
                'output' => substr($result['output'], 0, 500),
            ]);

            throw new RuntimeException('OpenCode prompt generation failed: '.$result['output']);
        }

        return trim($result['stdout']);
    }

    /**
     * Check if OpenCode is available and properly configured.
     */
    public function isConfigured(): bool
    {
        return empty($this->getConfigurationErrors());
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
     * Build the prompt for log entry analysis.
     */
    protected function buildAnalysisPrompt(array $logEntries): string
    {
        $formattedEntries = array_map(function ($entry) {
            return sprintf(
                "[%s] %s: %s\n%s",
                date('Y-m-d H:i:s', $entry['timestamp']),
                strtoupper($entry['level']),
                $entry['message'],
                $entry['stack_trace'] ?? ''
            );
        }, $logEntries);

        $entriesText = implode("\n\n---\n\n", $formattedEntries);

        return <<<PROMPT
You are an expert Laravel application debugger. Analyze the following log entries and extract structured issue information.

For each log entry, identify:
1. Error type (exception class, PHP error, database error, HTTP error, etc.)
2. Severity (critical, high, medium, low)
3. A clear, human-readable error message
4. Affected files from the stack trace
5. A brief suggestion for how to fix the issue

Severity guidelines:
- critical: Application crashes, database connection failures, critical service outages
- high: Uncaught exceptions, major feature failures, security issues
- medium: Caught exceptions with fallback, deprecation warnings affecting functionality
- low: Minor warnings, informational messages

If multiple log entries describe the same issue (same exception at same location), combine them into one issue.

IMPORTANT: Your response must be ONLY valid JSON with no additional text, markdown formatting, or code fences. Output a JSON object with a single "issues" key containing an array of issue objects.

Each issue object must have these fields:
- "id": string (unique identifier - hash of exception type + file + line)
- "type": string (e.g., "ErrorException", "QueryException")
- "severity": string (one of: "critical", "high", "medium", "low")
- "title": string (short, descriptive title)
- "message": string (full error message)
- "stack_trace": string (stack trace from the error log)
- "affected_files": array of strings (files mentioned in the stack trace)
- "suggested_fix": string (brief suggestion on how to fix)
- "log_level": string (original log level: error, critical, etc.)

Here are the log entries to analyze:

{$entriesText}
PROMPT;
    }

    /**
     * Build the prompt for generating an OpenCode fix prompt.
     */
    protected function buildPromptGenerationPrompt(array $issue, ?string $testFailureOutput = null): string
    {
        $context = "You are an expert at creating effective prompts for an AI coding agent. Generate a clear, actionable prompt that will help the agent fix a specific issue in a Laravel application.\n\n";
        $context .= "The prompt should:\n";
        $context .= "1. Clearly describe the issue that needs to be fixed\n";
        $context .= "2. Include the error message and relevant stack trace information\n";
        $context .= "3. Specify the affected files if known\n";
        $context .= "4. Provide context about what the code should do\n";
        $context .= "5. Be specific and actionable\n";
        $context .= "6. Be written in a direct, imperative style as if giving instructions to a skilled developer\n\n";

        $context .= "Here is the issue to create a prompt for:\n\n";
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

        if (! empty($issue['stack_trace'])) {
            $context .= "**Stack Trace**:\n{$issue['stack_trace']}\n\n";
        }

        if (! empty($issue['suggested_fix'])) {
            $context .= "**Suggested Fix**: {$issue['suggested_fix']}\n\n";
        }

        if ($testFailureOutput) {
            $context .= "**Previous Fix Attempt Failed**\n\n";
            $context .= "The previous fix attempt resulted in test failures. Here is the test output:\n\n";
            $context .= "```\n{$testFailureOutput}\n```\n\n";
            $context .= "The generated prompt must address these test failures. The fix should not only resolve the original error but also ensure all tests pass.\n\n";
        }

        $context .= 'IMPORTANT: Your response should be ONLY the generated prompt text, with no additional commentary, markdown formatting, or code fences. Output the prompt directly.';

        return $context;
    }

    /**
     * Parse the JSON output from OpenCode's analysis.
     */
    protected function parseAnalysisOutput(string $output): array
    {
        // Try to extract JSON from the output
        $json = $this->extractJson($output);

        if ($json === null) {
            Log::warning('[Paladin] Failed to parse OpenCode analysis output as JSON', [
                'output_length' => strlen($output),
                'output_preview' => substr($output, 0, 500),
            ]);

            throw new RuntimeException('Failed to parse OpenCode analysis output as valid JSON');
        }

        return $json['issues'] ?? [];
    }

    /**
     * Extract JSON from a string that may contain surrounding text.
     */
    protected function extractJson(string $text): ?array
    {
        // First, try direct JSON decode
        $decoded = json_decode(trim($text), true);
        if ($decoded !== null && isset($decoded['issues'])) {
            return $decoded;
        }

        // Try to find JSON within code fences
        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $text, $matches)) {
            $decoded = json_decode(trim($matches[1]), true);
            if ($decoded !== null && isset($decoded['issues'])) {
                return $decoded;
            }
        }

        // Try to find a JSON object containing "issues" by locating the key
        // and walking outward to find balanced braces
        $issuesPos = strpos($text, '"issues"');
        if ($issuesPos !== false) {
            // Walk backward to find the opening brace
            $startPos = null;
            $depth = 0;
            for ($i = $issuesPos - 1; $i >= 0; $i--) {
                if ($text[$i] === '}') {
                    $depth++;
                } elseif ($text[$i] === '{') {
                    if ($depth === 0) {
                        $startPos = $i;
                        break;
                    }
                    $depth--;
                }
            }

            if ($startPos !== null) {
                // Walk forward from startPos to find the matching closing brace
                $depth = 0;
                for ($i = $startPos; $i < strlen($text); $i++) {
                    if ($text[$i] === '{') {
                        $depth++;
                    } elseif ($text[$i] === '}') {
                        $depth--;
                        if ($depth === 0) {
                            $candidate = substr($text, $startPos, $i - $startPos + 1);
                            $decoded = json_decode($candidate, true);
                            if ($decoded !== null && isset($decoded['issues'])) {
                                return $decoded;
                            }
                            break;
                        }
                    }
                }
            }
        }

        return null;
    }
}
