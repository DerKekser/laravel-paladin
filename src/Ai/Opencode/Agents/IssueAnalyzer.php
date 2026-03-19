<?php

namespace Kekser\LaravelPaladin\Ai\Opencode\Agents;

use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Ai\Concerns\InteractsWithLogEntries;
use Kekser\LaravelPaladin\Services\OpenCodeRunner;
use RuntimeException;

/**
 * Analyzes log entries to extract structured issue information using OpenCode.
 */
class IssueAnalyzer
{
    use InteractsWithLogEntries;

    protected OpenCodeRunner $runner;

    public function __construct()
    {
        $this->runner = app(OpenCodeRunner::class);
    }

    /**
     * Analyze log entries and return structured issue information.
     *
     * @param  array  $logEntries  Array of log entry data
     * @return array Array of issue objects
     */
    public function analyze(array $logEntries): array
    {
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
     * Build the prompt for log entry analysis.
     *
     * @param  array  $logEntries  Array of log entry data
     * @return string Formatted prompt for analysis
     */
    protected function buildAnalysisPrompt(array $logEntries): string
    {
        $entriesText = $this->formatLogEntries($logEntries);

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
     * Parse the JSON output from analysis.
     *
     * @param  string  $output  Raw output from analysis
     * @return array Parsed issues array
     */
    public function parseAnalysisOutput(string $output): array
    {
        // Try to extract JSON from the output
        $json = $this->extractJson($output);

        if ($json === null) {
            Log::warning('[Paladin] Failed to parse analysis output as JSON', [
                'output_length' => strlen($output),
                'output_preview' => substr($output, 0, 500),
            ]);

            throw new RuntimeException('Failed to parse analysis output as valid JSON');
        }

        return $json['issues'] ?? [];
    }

    /**
     * Extract JSON from a string that may contain surrounding text.
     *
     * @param  string  $text  Text that may contain JSON
     * @return array|null Decoded JSON or null if not found
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
