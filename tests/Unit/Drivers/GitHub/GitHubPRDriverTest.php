<?php

namespace Kekser\LaravelPaladin\Tests\Unit\Drivers\GitHub;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Drivers\GitHub\GitHubPRDriver;
use Kekser\LaravelPaladin\Tests\Fixtures\Helpers\CreatesTestRepository;
use Kekser\LaravelPaladin\Tests\TestCase;
use RuntimeException;

class GitHubPRDriverTest extends TestCase
{
    use CreatesTestRepository;

    protected GitHubPRDriver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'paladin.providers.github.token' => 'test-token-123',
            'paladin.providers.github.api_url' => 'https://api.github.com',
        ]);

        $this->driver = new GitHubPRDriver;

        Log::spy();
    }

    /** @test */
    public function it_checks_if_driver_is_configured()
    {
        config(['paladin.providers.github.token' => 'test-token']);
        $driver = new GitHubPRDriver;

        $this->assertTrue($driver->isConfigured());
    }

    /** @test */
    public function it_returns_false_when_token_is_missing()
    {
        config(['paladin.providers.github.token' => null]);
        $driver = new GitHubPRDriver;

        $this->assertFalse($driver->isConfigured());
    }

    /** @test */
    public function it_returns_false_when_token_is_empty()
    {
        config(['paladin.providers.github.token' => '']);
        $driver = new GitHubPRDriver;

        $this->assertFalse($driver->isConfigured());
    }

    /** @test */
    public function it_throws_exception_when_not_configured()
    {
        config(['paladin.providers.github.token' => null]);
        $driver = new GitHubPRDriver;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GitHub driver is not properly configured');

        $driver->createPullRequest('feature-branch', 'Test PR', 'Test body');
    }

    /** @test */
    public function it_creates_pull_request_successfully()
    {
        $testRepo = $this->createTestRepository();

        // Add a remote to the test repository
        exec("cd {$testRepo} && git remote add origin git@github.com:owner/test-repo.git 2>&1");

        Http::fake([
            'api.github.com/repos/owner/test-repo/pulls' => Http::response([
                'html_url' => 'https://github.com/owner/test-repo/pull/123',
                'number' => 123,
            ], 201),
        ]);

        // Change to test repo directory for git commands
        $originalDir = getcwd();
        chdir($testRepo);

        $url = $this->driver->createPullRequest(
            'feature-branch',
            'Add new feature',
            'This PR adds a new feature',
            'main'
        );

        chdir($originalDir);

        $this->assertEquals('https://github.com/owner/test-repo/pull/123', $url);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://api.github.com/repos/owner/test-repo/pulls'
                && $request['title'] === 'Add new feature'
                && $request['body'] === 'This PR adds a new feature'
                && $request['head'] === 'feature-branch'
                && $request['base'] === 'main'
                && $request->hasHeader('Authorization', 'Bearer test-token-123');
        });

        Log::shouldHaveReceived('info')
            ->with('[Paladin] Creating GitHub pull request', \Mockery::any());

        Log::shouldHaveReceived('info')
            ->with('[Paladin] GitHub PR created successfully', ['url' => 'https://github.com/owner/test-repo/pull/123']);

        // Cleanup
        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_uses_configured_api_url()
    {
        config(['paladin.providers.github.api_url' => 'https://api.github.enterprise.com']);

        $testRepo = $this->createTestRepository();
        exec("cd {$testRepo} && git remote add origin https://github.com/owner/test-repo.git 2>&1");

        Http::fake([
            '*' => Http::response([
                'html_url' => 'https://github.enterprise.com/owner/test-repo/pull/1',
            ], 201),
        ]);

        $driver = new GitHubPRDriver;

        $originalDir = getcwd();
        chdir($testRepo);

        $driver->createPullRequest('test-branch', 'Test', 'Body');

        chdir($originalDir);

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://api.github.enterprise.com');
        });

        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_returns_null_on_api_failure()
    {
        $testRepo = $this->createTestRepository();
        exec("cd {$testRepo} && git remote add origin git@github.com:owner/test-repo.git 2>&1");

        Http::fake([
            'api.github.com/repos/owner/test-repo/pulls' => Http::response([
                'message' => 'Validation failed',
            ], 422),
        ]);

        $originalDir = getcwd();
        chdir($testRepo);

        $url = $this->driver->createPullRequest('feature', 'Title', 'Body');

        chdir($originalDir);

        $this->assertNull($url);

        Log::shouldHaveReceived('error')
            ->with('[Paladin] Failed to create GitHub PR', \Mockery::any());

        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_handles_http_exceptions()
    {
        $testRepo = $this->createTestRepository();
        exec("cd {$testRepo} && git remote add origin git@github.com:owner/test-repo.git 2>&1");

        Http::fake(function () {
            throw new \Exception('Network error');
        });

        $originalDir = getcwd();
        chdir($testRepo);

        $url = $this->driver->createPullRequest('feature', 'Title', 'Body');

        chdir($originalDir);

        $this->assertNull($url);

        Log::shouldHaveReceived('error')
            ->with('[Paladin] GitHub PR creation error', ['error' => 'Network error']);

        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_parses_ssh_repository_url()
    {
        $testRepo = $this->createTestRepository();
        exec("cd {$testRepo} && git remote add origin git@github.com:laravel/framework.git 2>&1");

        Http::fake([
            'api.github.com/repos/laravel/framework/pulls' => Http::response([
                'html_url' => 'https://github.com/laravel/framework/pull/1',
            ], 201),
        ]);

        $originalDir = getcwd();
        chdir($testRepo);

        $this->driver->createPullRequest('test', 'Test', 'Body');

        chdir($originalDir);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'laravel/framework');
        });

        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_parses_https_repository_url()
    {
        $testRepo = $this->createTestRepository();
        exec("cd {$testRepo} && git remote add origin https://github.com/symfony/symfony.git 2>&1");

        Http::fake([
            'api.github.com/repos/symfony/symfony/pulls' => Http::response([
                'html_url' => 'https://github.com/symfony/symfony/pull/1',
            ], 201),
        ]);

        $originalDir = getcwd();
        chdir($testRepo);

        $this->driver->createPullRequest('test', 'Test', 'Body');

        chdir($originalDir);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'symfony/symfony');
        });

        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_removes_git_extension_from_repository()
    {
        $testRepo = $this->createTestRepository();
        exec("cd {$testRepo} && git remote add origin https://github.com/owner/repo.git 2>&1");

        Http::fake();

        $originalDir = getcwd();
        chdir($testRepo);

        $this->driver->createPullRequest('test', 'Test', 'Body');

        chdir($originalDir);

        // Should use "owner/repo" not "owner/repo.git"
        Http::assertSent(function ($request) {
            $url = $request->url();

            // The URL should contain "repos/owner/repo/pulls" without the .git extension
            return str_contains($url, '/repos/owner/repo/pulls')
                && ! str_contains($url, 'repo.git');
        });

        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_returns_null_when_remote_url_not_found()
    {
        $testRepo = $this->createTestRepository();
        // Don't add any remote

        $originalDir = getcwd();
        chdir($testRepo);

        Http::fake([
            '*' => Http::response(['html_url' => 'test'], 201),
        ]);

        $url = $this->driver->createPullRequest('test', 'Test', 'Body');

        chdir($originalDir);

        $this->assertNull($url);

        Log::shouldHaveReceived('error')
            ->with('[Paladin] GitHub PR creation error', \Mockery::any());

        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_returns_null_for_non_github_repository()
    {
        $testRepo = $this->createTestRepository();
        exec("cd {$testRepo} && git remote add origin git@gitlab.com:owner/repo.git 2>&1");

        Http::fake([
            '*' => Http::response(['html_url' => 'test'], 201),
        ]);

        $originalDir = getcwd();
        chdir($testRepo);

        $url = $this->driver->createPullRequest('test', 'Test', 'Body');

        chdir($originalDir);

        $this->assertNull($url);

        Log::shouldHaveReceived('error')
            ->with('[Paladin] GitHub PR creation error', \Mockery::any());

        $this->cleanupTestRepository($testRepo);
    }

    /** @test */
    public function it_caches_repository_after_first_parse()
    {
        $testRepo = $this->createTestRepository();
        exec("cd {$testRepo} && git remote add origin git@github.com:owner/repo.git 2>&1");

        Http::fake([
            'api.github.com/repos/owner/repo/pulls' => Http::response([
                'html_url' => 'https://github.com/owner/repo/pull/1',
            ], 201),
        ]);

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
}
