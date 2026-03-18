<?php

namespace Kekser\LaravelPaladin\Drivers\GitHub;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Contracts\PullRequestDriver;
use RuntimeException;

class GitHubPRDriver implements PullRequestDriver
{
    protected ?string $token;

    protected string $apiUrl;

    protected ?string $repository = null;

    public function __construct()
    {
        $this->token = config('paladin.providers.github.token');
        $this->apiUrl = config('paladin.providers.github.api_url', 'https://api.github.com');
    }

    /**
     * Create a pull request on GitHub.
     */
    public function createPullRequest(
        string $branch,
        string $title,
        string $body,
        string $baseBranch = 'main'
    ): ?string {
        if (! $this->isConfigured()) {
            throw new RuntimeException('GitHub driver is not properly configured');
        }

        try {
            $repository = $this->getRepository();

            [$owner, $repo] = explode('/', $repository);

            Log::info('[Paladin] Creating GitHub pull request', [
                'repository' => $repository,
                'branch' => $branch,
                'base' => $baseBranch,
            ]);

            $response = Http::withToken($this->token)
                ->post("{$this->apiUrl}/repos/{$owner}/{$repo}/pulls", [
                    'title' => $title,
                    'body' => $body,
                    'head' => $branch,
                    'base' => $baseBranch,
                ]);

            if (! $response->successful()) {
                Log::error('[Paladin] Failed to create GitHub PR', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $prUrl = $response->json('html_url');

            Log::info('[Paladin] GitHub PR created successfully', [
                'url' => $prUrl,
            ]);

            return $prUrl;
        } catch (\Exception $e) {
            Log::error('[Paladin] GitHub PR creation error', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Check if the driver is properly configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->token);
    }

    /**
     * Get the repository from git remote.
     */
    protected function getRepository(): string
    {
        if ($this->repository) {
            return $this->repository;
        }

        // Get the remote URL from git
        exec('git remote get-url origin 2>&1', $output, $returnCode);

        if ($returnCode !== 0 || empty($output)) {
            throw new RuntimeException('Failed to get git remote URL');
        }

        $remoteUrl = $output[0];

        // Parse repository from various URL formats
        // SSH: git@github.com:owner/repo.git
        // HTTPS: https://github.com/owner/repo.git
        if (preg_match('/github\.com[\/:]([^\/]+\/[^\/]+?)(\.git)?$/', $remoteUrl, $matches)) {
            $this->repository = str_replace('.git', '', $matches[1]);

            return $this->repository;
        }

        throw new RuntimeException('Could not parse repository from git remote URL: '.$remoteUrl);
    }
}
