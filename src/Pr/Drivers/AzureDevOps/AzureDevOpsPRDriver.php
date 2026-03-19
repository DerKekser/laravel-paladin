<?php

namespace Kekser\LaravelPaladin\Pr\Drivers\AzureDevOps;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Contracts\PullRequestDriver;
use RuntimeException;

class AzureDevOpsPRDriver implements PullRequestDriver
{
    protected ?string $token;

    protected ?string $organization;

    protected ?string $project;

    protected string $apiUrl;

    protected ?string $repository = null;

    public function __construct()
    {
        $this->token = config('paladin.providers.azure-devops.token');
        $this->organization = config('paladin.providers.azure-devops.organization');
        $this->project = config('paladin.providers.azure-devops.project');
        $this->apiUrl = config('paladin.providers.azure-devops.api_url', 'https://dev.azure.com');
    }

    /**
     * Create a pull request on Azure DevOps.
     */
    public function createPullRequest(
        string $branch,
        string $title,
        string $body,
        string $baseBranch = 'main'
    ): ?string {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Azure DevOps driver is not properly configured');
        }

        try {
            $repositoryId = $this->getRepositoryId();

            Log::info('[Paladin] Creating Azure DevOps pull request', [
                'organization' => $this->organization,
                'project' => $this->project,
                'repository' => $repositoryId,
                'branch' => $branch,
                'base' => $baseBranch,
            ]);

            $url = "{$this->apiUrl}/{$this->organization}/{$this->project}/_apis/git/repositories/{$repositoryId}/pullrequests?api-version=7.0";

            $response = Http::withBasicAuth('', $this->token)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($url, [
                    'sourceRefName' => "refs/heads/{$branch}",
                    'targetRefName' => "refs/heads/{$baseBranch}",
                    'title' => $title,
                    'description' => $body,
                ]);

            if (! $response->successful()) {
                Log::error('[Paladin] Failed to create Azure DevOps PR', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            $prId = $response->json('pullRequestId');
            $prUrl = "{$this->apiUrl}/{$this->organization}/{$this->project}/_git/{$repositoryId}/pullrequest/{$prId}";

            Log::info('[Paladin] Azure DevOps PR created successfully', [
                'url' => $prUrl,
            ]);

            return $prUrl;
        } catch (\Exception $e) {
            Log::error('[Paladin] Azure DevOps PR creation error', [
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
        return empty($this->getConfigurationErrors());
    }

    /**
     * Get any configuration errors for the driver.
     *
     * @return array<string>
     */
    public function getConfigurationErrors(): array
    {
        $errors = [];

        if (empty($this->organization) || empty($this->token)) {
            $errors[] = 'Azure DevOps not fully configured. Set PALADIN_AZURE_DEVOPS_ORG and PALADIN_AZURE_DEVOPS_PAT in your .env file.';
        }

        if (empty($this->project)) {
            $errors[] = 'Azure DevOps project not configured. Set PALADIN_AZURE_DEVOPS_PROJECT in your .env file.';
        }

        return $errors;
    }

    /**
     * Get the repository ID from git remote.
     */
    protected function getRepositoryId(): string
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

        // Parse repository from Azure DevOps URL
        // HTTPS: https://dev.azure.com/organization/project/_git/repository
        // SSH: git@ssh.dev.azure.com:v3/organization/project/repository
        if (preg_match('/_git\/([^\/\?]+)/', $remoteUrl, $matches)) {
            $this->repository = $matches[1];

            return $this->repository;
        }

        if (preg_match('/v3\/[^\/]+\/[^\/]+\/([^\/]+)/', $remoteUrl, $matches)) {
            $this->repository = $matches[1];

            return $this->repository;
        }

        throw new RuntimeException('Could not parse repository from git remote URL: '.$remoteUrl);
    }
}
