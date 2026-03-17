<?php

namespace Kekser\LaravelPaladin\Ai\Opencode\Agents;

use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Services\OpenCodeRunner;
use RuntimeException;

/**
 * Generates prompts for fixing issues based on issue data using OpenCode.
 */
class PromptGenerator
{
    protected OpenCodeRunner $runner;

    public function __construct()
    {
        $this->runner = new OpenCodeRunner;
    }

    /**
     * Generate a fix prompt based on issue data.
     *
     * @param  array  $issue  Issue data array
     * @param  ?string  $testFailureOutput  Optional test failure output
     * @return string Generated prompt
     */
    public function generate(array $issue, ?string $testFailureOutput = null): string
    {
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
     * Build the prompt for generating an OpenCode fix prompt.
     *
     * @param  array  $issue  Issue data array
     * @param  ?string  $testFailureOutput  Optional test failure output
     * @return string Formatted prompt for generation
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
}
