<?php

namespace Kekser\LaravelPaladin\Ai\Concerns;

trait InteractsWithIssuePrompts
{
    /**
     * Build a structured context string from issue data.
     */
    protected function buildIssueContext(array $issue, ?string $testFailureOutput = null): string
    {
        $context = "**Issue Type**: {$issue['type']}\n";
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
        }

        return $context;
    }
}
