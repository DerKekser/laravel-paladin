<?php

namespace Kekser\LaravelPaladin\Contracts;

interface PullRequestDriver
{
    /**
     * Create a pull request.
     *
     * @param  string  $branch  Source branch name
     * @param  string  $title  PR title
     * @param  string  $body  PR description
     * @param  string  $baseBranch  Target branch (default: main)
     * @return string|null PR URL if successful, null otherwise
     */
    public function createPullRequest(
        string $branch,
        string $title,
        string $body,
        string $baseBranch = 'main'
    ): ?string;

    /**
     * Check if the driver is properly configured.
     */
    public function isConfigured(): bool;

    /**
     * Get any configuration errors for the driver.
     *
     * @return array<string>
     */
    public function getConfigurationErrors(): array;
}
