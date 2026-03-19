<?php

use Kekser\LaravelPaladin\Contracts\PullRequestDriver;
use Kekser\LaravelPaladin\Drivers\AzureDevOps\AzureDevOpsPRDriver;
use Kekser\LaravelPaladin\Drivers\Composite\CompositePullRequestDriver;
use Kekser\LaravelPaladin\Drivers\GitHub\GitHubPRDriver;
use Kekser\LaravelPaladin\Drivers\Mail\MailNotificationDriver;
use Kekser\LaravelPaladin\Services\PullRequestManager;

test('it uses configured drivers via composite driver', function () {
    config(['paladin.pr_provider' => 'github,mail']);

    $manager = new PullRequestManager;
    $driver = $manager->getDriver();

    expect($driver)->toBeInstanceOf(CompositePullRequestDriver::class);
});

test('it uses azure driver when configured', function () {
    config(['paladin.pr_provider' => 'azure-devops']);

    $manager = new PullRequestManager;
    $driver = $manager->getDriver();

    expect($driver)->toBeInstanceOf(CompositePullRequestDriver::class);
});

test('it uses mail driver when configured', function () {
    config(['paladin.pr_provider' => 'mail']);

    $manager = new PullRequestManager;
    $driver = $manager->getDriver();

    expect($driver)->toBeInstanceOf(CompositePullRequestDriver::class);
});

test('it always returns a composite driver', function () {
    config(['paladin.pr_provider' => 'github']);

    $manager = new PullRequestManager;
    $driver = $manager->getDriver();

    expect($driver)->toBeInstanceOf(CompositePullRequestDriver::class);
});


test('it throws exception for unknown provider', function () {
    config(['paladin.pr_provider' => 'unknown']);

    $manager = new PullRequestManager;
    $manager->createPullRequest('test-branch', 'Test PR', 'Test body');
})->throws(RuntimeException::class, 'Unknown PR provider');

test('it uses default base branch', function () {
    config(['paladin.pr_provider' => 'github']);
    config(['paladin.git.default_branch' => 'develop']);

    $mockDriver = Mockery::mock(GitHubPRDriver::class);
    $mockDriver->shouldReceive('createPullRequest')
        ->with('test-branch', 'Test PR', 'Test body', 'develop')
        ->once()
        ->andReturn('http://github.com/pr/1');

    app()->instance(GitHubPRDriver::class, $mockDriver);

    $manager = new PullRequestManager;

    $result = $manager->createPullRequest('test-branch', 'Test PR', 'Test body');

    expect($result)->toBe('http://github.com/pr/1');
});

test('it calls multiple drivers when configured', function () {
    config(['paladin.pr_provider' => 'github,mail']);

    $mockGithub = Mockery::mock(GitHubPRDriver::class);
    $mockGithub->shouldReceive('createPullRequest')
        ->with('test-branch', 'Test PR', 'Test body', 'main')
        ->once()
        ->andReturn('http://github.com/pr/1');

    $mockMail = Mockery::mock(MailNotificationDriver::class);
    $mockMail->shouldReceive('createPullRequest')
        ->with('test-branch', 'Test PR', 'Test body', 'main')
        ->once()
        ->andReturn(null);

    app()->instance(GitHubPRDriver::class, $mockGithub);
    app()->instance(MailNotificationDriver::class, $mockMail);

    $manager = new PullRequestManager;
    $result = $manager->createPullRequest('test-branch', 'Test PR', 'Test body');

    expect($result)->toBe('http://github.com/pr/1');
});

test('it returns null when no driver configured', function () {
    // Clear all driver configurations
    config(['paladin.providers.github.token' => null]);
    config(['paladin.providers.azure-devops.token' => null]);
    config(['paladin.providers.mail.to' => null]);

    $manager = new PullRequestManager;
    $driver = $manager->getDriver();

    expect($driver->isConfigured())->toBeFalse();
});
