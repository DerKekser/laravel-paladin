<?php

namespace Kekser\LaravelPaladin\Tests\Unit\Drivers\AzureDevOps;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Drivers\AzureDevOps\AzureDevOpsPRDriver;
use Kekser\LaravelPaladin\Tests\Fixtures\Helpers\CreatesTestRepository;
use Kekser\LaravelPaladin\Tests\TestCase;
use RuntimeException;

class AzureDevOpsPRDriverTest extends TestCase
{
    use CreatesTestRepository;

    protected AzureDevOpsPRDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'paladin.providers.azure-devops.token' => 'test-pat-token',
            'paladin.providers.azure-devops.organization' => 'my-org',
            'paladin.providers.azure-devops.project' => 'my-project',
            'paladin.providers.azure-devops.api_url' => 'https://dev.azure.com',
        ]);

        $this->driver = new AzureDevOpsPRDriver;

        Log::spy();
    }

    /** @test */
    public function it_checks_if_driver_is_configured()
    {
        $this->assertTrue($this->driver->isConfigured());
    }

    /** @test */
    public function it_returns_false_when_token_is_missing()
    {
        config(['paladin.providers.azure-devops.token' => null]);
        $driver = new AzureDevOpsPRDriver;

        $this->assertFalse($driver->isConfigured());
    }

    /** @test */
    public function it_returns_false_when_organization_is_missing()
    {
        config(['paladin.providers.azure-devops.organization' => null]);
        $driver = new AzureDevOpsPRDriver;

        $this->assertFalse($driver->isConfigured());
    }

    /** @test */
    public function it_returns_false_when_project_is_missing()
    {
        config(['paladin.providers.azure-devops.project' => null]);
        $driver = new AzureDevOpsPRDriver;

        $this->assertFalse($driver->isConfigured());
    }

    /** @test */
    public function it_throws_exception_when_not_configured()
    {
        config(['paladin.providers.azure-devops.token' => null]);
        $driver = new AzureDevOpsPRDriver;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Azure DevOps driver is not properly configured');

        $driver->createPullRequest('feature-branch', 'Test PR', 'Test body');
    }

    /** @test */
    public function it_creates_pull_request_successfully()
    {
        $testRepo = $this->createTestRepository();
        exec("cd {$testRepo} && git remote add origin https://dev.azure.com/my-org/my-project/_git/my-repo 2>&1");

        Http::fake([
            '*/_apis/git/repositories/my-repo/pullrequests*' => Http::response([
                'pullRequestId' => 123,
                'title' => 'Test PR',
            ], 201),
        ]);

        $originalDir = getcwd();
        chdir($testRepo);

        $url = $this->driver->createPullRequest(
            'feature-branch',
            'Add new feature',
            'This PR adds a new feature',
            'main'
        );

        chdir($originalDir);

        $this->assertEquals('https://dev.azure.com/my-org/my-project/_git/my-repo/pullrequest/123', $url);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'my-org/my-project/_apis/git/repositories/my-repo/pullrequests')
                && $request['title'] === 'Add new feature'
                && $request['description'] === 'This PR adds a new feature'
                && $request['sourceRefName'] === 'refs/heads/feature-branch'
                && $request['targetRefName'] === 'refs/heads/main';
        });

        Log::shouldHaveReceived('info')
            ->with('[Paladin] Creating Azure DevOps pull request', \Mockery::any());

        Log::shouldHaveReceived('info')
            ->with('[Paladin] Azure DevOps PR created successfully', \Mockery::any());

        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_uses_basic_auth_with_empty_username()
    {
        $testRepo = $this->createTestRepository();
        exec("cd {$testRepo} && git remote add origin https://dev.azure.com/my-org/my-project/_git/my-repo 2>&1");

        Http::fake();

        $originalDir = getcwd();
        chdir($testRepo);

        $this->driver->createPullRequest('test', 'Test', 'Body');

        chdir($originalDir);

        // Azure DevOps uses basic auth with empty username and PAT as password
        Http::assertSent(function ($request) {
            // Check that Authorization header is present (basic auth)
            return $request->hasHeader('Authorization');
        });

        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_uses_configured_api_url()
    {
        config(['paladin.providers.azure-devops.api_url' => 'https://custom.azure.com']);

        $testRepo = $this->createTestRepository();
        exec("cd {$testRepo} && git remote add origin https://custom.azure.com/my-org/my-project/_git/my-repo 2>&1");

        Http::fake();

        $driver = new AzureDevOpsPRDriver;

        $originalDir = getcwd();
        chdir($testRepo);

        $driver->createPullRequest('test', 'Test', 'Body');

        chdir($originalDir);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://custom.azure.com');
        });

        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_returns_null_on_api_failure()
    {
        $testRepo = $this->createTestRepository();
        exec("cd {$testRepo} && git remote add origin https://dev.azure.com/my-org/my-project/_git/my-repo 2>&1");

        Http::fake([
            '*' => Http::response(['message' => 'Validation failed'], 400),
        ]);

        $originalDir = getcwd();
        chdir($testRepo);

        $url = $this->driver->createPullRequest('feature', 'Title', 'Body');

        chdir($originalDir);

        $this->assertNull($url);

        Log::shouldHaveReceived('error')
            ->with('[Paladin] Failed to create Azure DevOps PR', \Mockery::any());

        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_handles_http_exceptions()
    {
        $testRepo = $this->createTestRepository();
        exec("cd {$testRepo} && git remote add origin https://dev.azure.com/my-org/my-project/_git/my-repo 2>&1");

        Http::fake(function () {
            throw new \Exception('Network error');
        });

        $originalDir = getcwd();
        chdir($testRepo);

        $url = $this->driver->createPullRequest('feature', 'Title', 'Body');

        chdir($originalDir);

        $this->assertNull($url);

        Log::shouldHaveReceived('error')
            ->with('[Paladin] Azure DevOps PR creation error', ['error' => 'Network error']);

        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_parses_https_repository_url()
    {
        $testRepo = $this->createTestRepository();
        exec("cd {$testRepo} && git remote add origin https://dev.azure.com/contoso/FabrikamFiber/_git/FabrikamFiber 2>&1");

        Http::fake();

        $originalDir = getcwd();
        chdir($testRepo);

        $this->driver->createPullRequest('test', 'Test', 'Body');

        chdir($originalDir);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'repositories/FabrikamFiber/pullrequests');
        });

        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_parses_ssh_repository_url()
    {
        $testRepo = $this->createTestRepository();
        exec("cd {$testRepo} && git remote add origin git@ssh.dev.azure.com:v3/contoso/FabrikamFiber/FabrikamRepo 2>&1");

        Http::fake();

        $originalDir = getcwd();
        chdir($testRepo);

        $this->driver->createPullRequest('test', 'Test', 'Body');

        chdir($originalDir);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'repositories/FabrikamRepo/pullrequests');
        });

        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_handles_repository_names_with_query_params()
    {
        $testRepo = $this->createTestRepository();
        exec("cd {$testRepo} && git remote add origin https://dev.azure.com/org/project/_git/repo?version=GBmaster 2>&1");

        Http::fake();

        $originalDir = getcwd();
        chdir($testRepo);

        $this->driver->createPullRequest('test', 'Test', 'Body');

        chdir($originalDir);

        Http::assertSent(function ($request) {
            // Should use "repo" not "repo?version=GBmaster"
            return str_contains($request->url(), 'repositories/repo/pullrequests')
                && ! str_contains($request->url(), '?version');
        });

        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_returns_null_when_remote_url_not_found()
    {
        $testRepo = $this->createTestRepository();
        // Don't add any remote

        Http::fake();

        $originalDir = getcwd();
        chdir($testRepo);

        $url = $this->driver->createPullRequest('test', 'Test', 'Body');

        chdir($originalDir);

        $this->assertNull($url);

        Log::shouldHaveReceived('error')
            ->with('[Paladin] Azure DevOps PR creation error', \Mockery::any());

        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_returns_null_for_non_azure_repository()
    {
        $testRepo = $this->createTestRepository();
        exec("cd {$testRepo} && git remote add origin https://github.com/owner/repo.git 2>&1");

        Http::fake();

        $originalDir = getcwd();
        chdir($testRepo);

        $url = $this->driver->createPullRequest('test', 'Test', 'Body');

        chdir($originalDir);

        $this->assertNull($url);

        Log::shouldHaveReceived('error')
            ->with('[Paladin] Azure DevOps PR creation error', \Mockery::any());

        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_caches_repository_after_first_parse()
    {
        $testRepo = $this->createTestRepository();
        exec("cd {$testRepo} && git remote add origin https://dev.azure.com/org/proj/_git/repo 2>&1");

        Http::fake();

        $originalDir = getcwd();
        chdir($testRepo);

        // First call - parses repository
        $this->driver->createPullRequest('test1', 'Test 1', 'Body');

        // Second call - should use cached repository
        $this->driver->createPullRequest('test2', 'Test 2', 'Body');

        chdir($originalDir);

        // Should have made 2 API calls
        Http::assertSentCount(2);

        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_formats_branch_refs_correctly()
    {
        $testRepo = $this->createTestRepository();
        exec("cd {$testRepo} && git remote add origin https://dev.azure.com/org/proj/_git/repo 2>&1");

        Http::fake();

        $originalDir = getcwd();
        chdir($testRepo);

        $this->driver->createPullRequest('my-feature', 'Test', 'Body', 'develop');

        chdir($originalDir);

        Http::assertSent(function ($request) {
            return $request['sourceRefName'] === 'refs/heads/my-feature'
                && $request['targetRefName'] === 'refs/heads/develop';
        });

        $this->cleanupTestRepository($testRepo);
    }
}
