<?php

namespace Kekser\LaravelPaladin\Services;

class TemplateGenerator
{
    /**
     * Generate commit message from template.
     *
     * @param  array  $issue  Issue data with title, message, severity
     * @param  int  $attemptNumber  Current attempt number
     * @param  int  $maxAttempts  Maximum number of attempts
     * @return string Generated commit message
     */
    public function generateCommitMessage(array $issue, int $attemptNumber, int $maxAttempts): string
    {
        $template = config('paladin.git.commit_message_template');

        return $this->interpolateTemplate($template, [
            'issue_title' => $issue['title'] ?? '',
            'issue_description' => $issue['message'] ?? '',
            'severity' => $issue['severity'] ?? '',
            'attempt_number' => $attemptNumber,
            'max_attempts' => $maxAttempts,
        ]);
    }

    /**
     * Generate PR title from template.
     *
     * @param  array  $issue  Issue data with title
     * @return string Generated PR title
     */
    public function generatePRTitle(array $issue): string
    {
        $template = config('paladin.git.pr_title_template');

        return $this->interpolateTemplate($template, [
            'issue_title' => $issue['title'] ?? '',
        ]);
    }

    /**
     * Generate PR body from template.
     *
     * @param  array  $issue  Issue data with type, severity, affected_files, message, stack_trace
     * @param  int  $attemptNumber  Current attempt number
     * @param  int  $maxAttempts  Maximum number of attempts
     * @return string Generated PR body
     */
    public function generatePRBody(array $issue, int $attemptNumber, int $maxAttempts): string
    {
        $template = config('paladin.git.pr_body_template');

        return $this->interpolateTemplate($template, [
            'issue_type' => $issue['type'] ?? '',
            'severity' => strtoupper($issue['severity'] ?? ''),
            'affected_files' => implode(', ', $issue['affected_files'] ?? []),
            'issue_description' => $issue['message'] ?? '',
            'stack_trace' => $issue['stack_trace'] ?? 'N/A',
            'attempt_number' => $attemptNumber,
            'max_attempts' => $maxAttempts,
        ]);
    }

    /**
     * Generate branch name for an issue.
     *
     * @param  array  $issue  Issue data with id
     * @return string Generated branch name
     */
    public function generateBranchName(array $issue): string
    {
        $prefix = config('paladin.git.branch_prefix') ?: 'paladin/fix';

        return "{$prefix}-".substr($issue['id'], 0, 8);
    }

    /**
     * Interpolate template placeholders with values.
     *
     * @param  string  $template  Template string with {placeholders}
     * @param  array  $values  Key-value pairs for replacement
     * @return string Interpolated string
     */
    protected function interpolateTemplate(string $template, array $values): string
    {
        $placeholders = [];
        foreach ($values as $key => $value) {
            $placeholders["{{$key}}"] = $value;
        }

        return strtr($template, $placeholders);
    }
}
