<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Drivers\AzureDevOps\AzureDevOpsPRDriver;
use Kekser\LaravelPaladin\Tests\Fixtures\Helpers\CreatesTestRepository;

uses(CreatesTestRepository::class);

beforeEach(function () {
    config([
        'paladin.providers.azure-devops.token' => 'test-pat-token',
        'paladin.providers.azure-devops.organization' => 'my-org',
        'paladin.providers.azure-devops.project' => 'my-project',
        'paladin.providers.azure-devops.api_url' => 'https://dev.azure.com',
    ]);

    $this->driver = new AzureDevOpsPRDriver;

    Log::spy();
});

test('it checks if driver is configured', function () {
    expect($this->driver->isConfigured())->toBeTrue();
});

test('it returns false when token is missing', function () {
    config(['paladin.providers.azure-devops.token' => null]);
    $driver = new AzureDevOpsPRDriver;

    expect($driver->isConfigured())->toBeFalse();
});

test('it returns false when organization is missing', function () {
    config(['paladin.providers.azure-devops.organization' => null]);
    $driver = new AzureDevOpsPRDriver;

    expect($driver->isConfigured())->toBeFalse();
});

test('it returns false when project is missing', function () {
    config(['paladin.providers.azure-devops.project' => null]);
    $driver = new AzureDevOpsPRDriver;

    expect($driver->isConfigured())->toBeFalse();
});

test('it throws exception when not configured', function () {
    config(['paladin.providers.azure-devops.token' => null]);
    $driver = new AzureDevOpsPRDriver;

    $driver->createPullRequest('feature-branch', 'Test PR', 'Test body');
})->throws(RuntimeException::class, 'Azure DevOps driver is not properly configured');

test('it creates pull request successfully', function () {
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

    expect($url)->toBe('https://dev.azure.com/my-org/my-project/_git/my-repo/pullrequest/123');

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'my-org/my-project/_apis/git/repositories/my-repo/pullrequests')
            && $request['title'] === 'Add new feature'
            && $request['description'] === 'This PR adds a new feature'
            && $request['sourceRefName'] === 'refs/heads/feature-branch'
            && $request['targetRefName'] === 'refs/heads/main';
    });

    Log::shouldHaveReceived('info')
        ->with('[Paladin] Creating Azure DevOps pull request', Mockery::any());

    Log::shouldHaveReceived('info')
        ->with('[Paladin] Azure DevOps PR created successfully', Mockery::any());

    $this->cleanupTestRepository($testRepo);
});

test('it uses basic auth with empty username', function () {
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
});

test('it uses configured api url', function () {
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
});

test('it returns null on api failure', function () {
    $testRepo = $this->createTestRepository();
    exec("cd {$testRepo} && git remote add origin https://dev.azure.com/my-org/my-project/_git/my-repo 2>&1");

    Http::fake([
        '*' => Http::response(['message' => 'Validation failed'], 400),
    ]);

    $originalDir = getcwd();
    chdir($testRepo);

    $url = $this->driver->createPullRequest('feature', 'Title', 'Body');

    chdir($originalDir);

    expect($url)->toBeNull();

    Log::shouldHaveReceived('error')
        ->with('[Paladin] Failed to create Azure DevOps PR', Mockery::any());

    $this->cleanupTestRepository($testRepo);
});

test('it handles http exceptions', function () {
    $testRepo = $this->createTestRepository();
    exec("cd {$testRepo} && git remote add origin https://dev.azure.com/my-org/my-project/_git/my-repo 2>&1");

    Http::fake(function () {
        throw new Exception('Network error');
    });

    $originalDir = getcwd();
    chdir($testRepo);

    $url = $this->driver->createPullRequest('feature', 'Title', 'Body');

    chdir($originalDir);

    expect($url)->toBeNull();

    Log::shouldHaveReceived('error')
        ->with('[Paladin] Azure DevOps PR creation error', ['error' => 'Network error']);

    $this->cleanupTestRepository($testRepo);
});

test('it parses https repository url', function () {
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
});

test('it parses ssh repository url', function () {
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
});

test('it handles repository names with query params', function () {
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
});

test('it returns null when remote url not found', function () {
    $testRepo = $this->createTestRepository();
    // Don't add any remote

    Http::fake();

    $originalDir = getcwd();
    chdir($testRepo);

    $url = $this->driver->createPullRequest('test', 'Test', 'Body');

    chdir($originalDir);

    expect($url)->toBeNull();

    Log::shouldHaveReceived('error')
        ->with('[Paladin] Azure DevOps PR creation error', Mockery::any());

    $this->cleanupTestRepository($testRepo);
});

test('it returns null for non azure repository', function () {
    $testRepo = $this->createTestRepository();
    exec("cd {$testRepo} && git remote add origin https://github.com/owner/repo.git 2>&1");

    Http::fake();

    $originalDir = getcwd();
    chdir($testRepo);

    $url = $this->driver->createPullRequest('test', 'Test', 'Body');

    chdir($originalDir);

    expect($url)->toBeNull();

    Log::shouldHaveReceived('error')
        ->with('[Paladin] Azure DevOps PR creation error', Mockery::any());

    $this->cleanupTestRepository($testRepo);
});

test('it caches repository after first parse', function () {
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
});

test('it formats branch refs correctly', function () {
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
});
