<?php

namespace Kekser\LaravelPaladin\Services;

use Kekser\LaravelPaladin\Contracts\PullRequestDriver;
use Kekser\LaravelPaladin\Drivers\AzureDevOps\AzureDevOpsPRDriver;
use Kekser\LaravelPaladin\Drivers\GitHub\GitHubPRDriver;
use Kekser\LaravelPaladin\Drivers\Mail\MailNotificationDriver;
use RuntimeException;

class PullRequestManager
{
    /**
     * The active PR driver instance.
     */
    protected ?PullRequestDriver $driver = null;

    /**
     * Create a pull request using the configured provider.
     */
    public function createPullRequest(
        string $branch,
        string $title,
        string $body,
        ?string $baseBranch = null
    ): ?string {
        $baseBranch = $baseBranch ?? config('paladin.git.default_branch', 'main');

        return $this->getDriver()->createPullRequest($branch, $title, $body, $baseBranch);
    }

    /**
     * Get the appropriate PR driver based on configuration.
     */
    public function getDriver(): PullRequestDriver
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        $provider = config('paladin.pr_provider', 'github');

        $this->driver = match ($provider) {
            'github' => new GitHubPRDriver,
            'azure-devops' => new AzureDevOpsPRDriver,
            'mail' => new MailNotificationDriver,
            default => throw new RuntimeException("Unknown PR provider: {$provider}"),
        };

        return $this->driver;
    }

    /**
     * Explicitly set the PR driver instance.
     */
    public function setDriver(PullRequestDriver $driver): self
    {
        $this->driver = $driver;

        return $this;
    }

    /**
     * Get the first configured and available driver.
     */
    public function getFirstAvailableDriver(): ?PullRequestDriver
    {
        $drivers = [
            new GitHubPRDriver,
            new AzureDevOpsPRDriver,
            new MailNotificationDriver,
        ];

        foreach ($drivers as $driver) {
            if ($driver->isConfigured()) {
                return $driver;
            }
        }

        return null;
    }
}
