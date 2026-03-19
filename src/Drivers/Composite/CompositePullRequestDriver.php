<?php

namespace Kekser\LaravelPaladin\Drivers\Composite;

use Kekser\LaravelPaladin\Contracts\PullRequestDriver;

class CompositePullRequestDriver implements PullRequestDriver
{
    /**
     * The array of PR drivers to delegate to.
     *
     * @var array<PullRequestDriver>
     */
    protected array $drivers;

    /**
     * Create a new composite PR driver instance.
     *
     * @param  array<PullRequestDriver>  $drivers
     */
    public function __construct(array $drivers)
    {
        $this->drivers = $drivers;
    }

    /**
     * Create a pull request using all drivers.
     *
     * @param  string  $branch  Source branch name
     * @param  string  $title  PR title
     * @param  string  $body  PR description
     * @param  string  $baseBranch  Target branch (default: main)
     * @return string|null The first successful PR URL, or null if all fail
     */
    public function createPullRequest(
        string $branch,
        string $title,
        string $body,
        string $baseBranch = 'main'
    ): ?string {
        $firstUrl = null;

        foreach ($this->drivers as $driver) {
            $url = $driver->createPullRequest($branch, $title, $body, $baseBranch);

            if ($url !== null && $firstUrl === null) {
                $firstUrl = $url;
            }
        }

        return $firstUrl;
    }

    /**
     * Check if all drivers are properly configured.
     */
    public function isConfigured(): bool
    {
        if (empty($this->drivers)) {
            return false;
        }

        foreach ($this->drivers as $driver) {
            if (! $driver->isConfigured()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get any configuration errors for all drivers.
     *
     * @return array<string>
     */
    public function getConfigurationErrors(): array
    {
        $errors = [];

        if (empty($this->drivers)) {
            $errors[] = 'No drivers specified for composite PR provider.';
        }

        foreach ($this->drivers as $driver) {
            $errors = array_merge($errors, $driver->getConfigurationErrors());
        }

        return $errors;
    }
}
