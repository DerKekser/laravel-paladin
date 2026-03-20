<?php

namespace Kekser\LaravelPaladin\Ai\Simple\Agents;

use Kekser\LaravelPaladin\Ai\Concerns\InteractsWithIssuePrompts;

/**
 * Generates prompts for fixing issues based on issue data without using AI.
 */
class PromptGenerator
{
    use InteractsWithIssuePrompts;

    /**
     * Generate a fix prompt based on issue data.
     *
     * @param  array  $issue  Issue data array
     * @param  ?string  $testFailureOutput  Optional test failure output
     * @return string Generated prompt
     */
    public function generate(array $issue, ?string $testFailureOutput = null): string
    {
        $context = $this->buildIssueContext($issue, $testFailureOutput);

        // Build a comprehensive prompt without AI
        $prompt = $this->buildFixPrompt($issue, $context, $testFailureOutput);

        return $prompt;
    }

    /**
     * Build the fix prompt with all relevant information.
     */
    protected function buildFixPrompt(array $issue, string $context, ?string $testFailureOutput = null): string
    {
        $type = $issue['type'] ?? 'Unknown';
        $severity = $issue['severity'] ?? 'unknown';
        $title = $issue['title'] ?? 'Untitled Issue';
        $message = $issue['message'] ?? '';
        $stackTrace = $issue['stack_trace'] ?? '';
        $affectedFiles = $issue['affected_files'] ?? [];
        $suggestedFix = $issue['suggested_fix'] ?? '';
        $logLevel = $issue['log_level'] ?? 'error';

        $prompt = "Fix the following Laravel application issue:\n\n";

        // Issue details
        $prompt .= "ISSUE DETAILS:\n";
        $prompt .= "- Type: {$type}\n";
        $prompt .= "- Severity: {$severity}\n";
        $prompt .= "- Title: {$title}\n";
        $prompt .= "- Original Log Level: {$logLevel}\n\n";

        // Error message
        if (! empty($message)) {
            $prompt .= "ERROR MESSAGE:\n";
            $prompt .= "```\n{$message}\n```\n\n";
        }

        // Stack trace
        if (! empty($stackTrace)) {
            $prompt .= "STACK TRACE:\n";
            $prompt .= "```\n{$stackTrace}\n```\n\n";
        }

        // Affected files
        if (! empty($affectedFiles)) {
            $prompt .= "AFFECTED FILES:\n";
            foreach ($affectedFiles as $file) {
                $prompt .= "- {$file}\n";
            }
            $prompt .= "\n";
        }

        // Suggested fix
        if (! empty($suggestedFix)) {
            $prompt .= "SUGGESTED APPROACH:\n";
            $prompt .= "{$suggestedFix}\n\n";
        }

        // Context from previous test failures
        if (! empty($testFailureOutput)) {
            $prompt .= "PREVIOUS FIX ATTEMPT RESULTS:\n";
            $prompt .= "```\n{$testFailureOutput}\n```\n\n";
            $prompt .= "The previous fix did not resolve the issue. Review the test failures and adjust your approach accordingly.\n\n";
        }

        // Instructions
        $prompt .= "REQUIREMENTS:\n";
        $prompt .= "1. Analyze the error message and stack trace carefully\n";
        $prompt .= "2. Identify the root cause of the issue\n";
        $prompt .= "3. Fix the issue in the most appropriate file(s)\n";
        $prompt .= "4. Ensure the fix handles edge cases\n";
        $prompt .= "5. Do not break existing functionality\n";
        $prompt .= "6. Follow Laravel best practices and coding standards\n";

        if (! empty($affectedFiles)) {
            $prompt .= '7. Focus on these affected files: '.implode(', ', array_slice($affectedFiles, 0, 5))."\n";
        }

        // Severity-specific instructions
        if ($severity === 'critical') {
            $prompt .= "\nCRITICAL PRIORITY: This is a critical issue. Apply fixes immediately and ensure application stability.\n";
        } elseif ($severity === 'high') {
            $prompt .= "\nHIGH PRIORITY: This is a high severity issue. Address it promptly to prevent further problems.\n";
        }

        // Type-specific instructions
        $prompt .= $this->getTypeSpecificInstructions($type);

        return $prompt;
    }

    /**
     * Get type-specific instructions for the prompt.
     */
    protected function getTypeSpecificInstructions(string $type): string
    {
        $instructions = '';

        if (str_contains($type, 'Database') || str_contains($type, 'Query')) {
            $instructions .= "\nDATABASE ERROR NOTES:\n";
            $instructions .= "- Check for SQL syntax errors\n";
            $instructions .= "- Verify table and column names exist\n";
            $instructions .= "- Ensure database connection is configured correctly\n";
            $instructions .= "- Review migrations if schema issues are suspected\n";
        } elseif (str_contains($type, 'Http') || str_contains($type, 'Route')) {
            $instructions .= "\nHTTP/ROUTE ERROR NOTES:\n";
            $instructions .= "- Verify route definitions in routes/*.php files\n";
            $instructions .= "- Check controller methods exist and are public\n";
            $instructions .= "- Ensure middleware is properly configured\n";
            $instructions .= "- Verify request methods match route definitions\n";
        } elseif (str_contains($type, 'Validation')) {
            $instructions .= "\nVALIDATION ERROR NOTES:\n";
            $instructions .= "- Review validation rules in FormRequest or controller\n";
            $instructions .= "- Ensure input data matches expected format\n";
            $instructions .= "- Check for required fields\n";
            $instructions .= "- Verify custom validation rules are properly registered\n";
        } elseif (str_contains($type, 'Type') || str_contains($type, 'Argument')) {
            $instructions .= "\nTYPE ERROR NOTES:\n";
            $instructions .= "- Check function/method signatures\n";
            $instructions .= "- Verify parameter types match declarations\n";
            $instructions .= "- Ensure return type declarations are correct\n";
            $instructions .= "- Review type hints and cast operations\n";
        } elseif (str_contains($type, 'Undefined')) {
            $instructions .= "\nUNDEFINED ERROR NOTES:\n";
            $instructions .= "- Check for typos in variable, function, or class names\n";
            $instructions .= "- Ensure files are properly autoloaded\n";
            $instructions .= "- Verify namespace declarations\n";
            $instructions .= "- Check import/use statements\n";
        } elseif (str_contains($type, 'Model') || str_contains($type, 'Eloquent')) {
            $instructions .= "\nMODEL ERROR NOTES:\n";
            $instructions .= "- Verify the model class exists and is namespaced correctly\n";
            $instructions .= "- Check database table name configuration\n";
            $instructions .= "- Ensure relationships are properly defined\n";
            $instructions .= "- Review fillable/guarded attributes\n";
        } elseif (str_contains($type, 'Auth') || str_contains($type, 'Access')) {
            $instructions .= "\nAUTHENTICATION/AUTHORIZATION NOTES:\n";
            $instructions .= "- Check authentication middleware\n";
            $instructions .= "- Verify user session status\n";
            $instructions .= "- Review authorization gates and policies\n";
            $instructions .= "- Ensure proper user permissions\n";
        } elseif (str_contains($type, 'File')) {
            $instructions .= "\nFILE ERROR NOTES:\n";
            $instructions .= "- Verify file paths are correct\n";
            $instructions .= "- Check file permissions and ownership\n";
            $instructions .= "- Ensure disk/storage configuration is correct\n";
            $instructions .= "- Check available disk space\n";
        } elseif (str_contains($type, 'View') || str_contains($type, 'Blade')) {
            $instructions .= "\nVIEW ERROR NOTES:\n";
            $instructions .= "- Check view file exists in resources/views\n";
            $instructions .= "- Verify blade syntax is correct\n";
            $instructions .= "- Ensure view variables are passed correctly\n";
            $instructions .= "- Check for @include or @extends issues\n";
        }

        return $instructions;
    }
}
