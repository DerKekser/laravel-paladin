<?php

namespace Kekser\LaravelPaladin\Services;

use Kekser\LaravelPaladin\Contracts\PullRequestDriver;
use Kekser\LaravelPaladin\Drivers\Composite\CompositePullRequestDriver;
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

        $this->driver = $this->resolveCompositeDriver();

        return $this->driver;
    }

    /**
     * Resolve a driver instance by its identifier.
     */
    protected function resolveDriver(string $provider): PullRequestDriver
    {
        $config = config("paladin.providers.{$provider}.driver");
        $class = is_array($config) ? ($config['driver'] ?? null) : $config;

        if (! $class || ! class_exists($class)) {
            throw new RuntimeException("Unknown PR provider: {$provider}");
        }

        return app($class);
    }

    /**
     * Resolve the composite driver instance.
     */
    protected function resolveCompositeDriver(): CompositePullRequestDriver
    {
        $driverIdentifiers = config('paladin.pr_provider', 'github');

        if (is_string($driverIdentifiers)) {
            $driverIdentifiers = explode(',', $driverIdentifiers);
        }

        $drivers = [];
        foreach ($driverIdentifiers as $identifier) {
            $identifier = trim($identifier);
            if (empty($identifier)) {
                continue;
            }

            $drivers[] = $this->resolveDriver($identifier);
        }

        return new CompositePullRequestDriver($drivers);
    }
}
