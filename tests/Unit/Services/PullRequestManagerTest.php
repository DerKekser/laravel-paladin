<?php

use Kekser\LaravelPaladin\Contracts\PullRequestDriver;
use Kekser\LaravelPaladin\Drivers\AzureDevOps\AzureDevOpsPRDriver;
use Kekser\LaravelPaladin\Drivers\Mail\MailNotificationDriver;
use Kekser\LaravelPaladin\Services\PullRequestManager;

test('it uses github driver when configured', function () {
    config(['paladin.pr_provider' => 'github']);

    $manager = new PullRequestManager;
    $driver1 = $manager->getDriver();
    $driver2 = $manager->getDriver();

    expect($driver1)->toBe($driver2);
});

test('it can explicitly set the driver', function () {
    $manager = new PullRequestManager;
    $driver = Mockery::mock(PullRequestDriver::class);

    $manager->setDriver($driver);

    expect($manager->getDriver())->toBe($driver);
});

test('it uses azure driver when configured', function () {
    config(['paladin.pr_provider' => 'azure-devops']);

    $manager = new PullRequestManager;
    $driver = $manager->getDriver();

    expect($driver)->toBeInstanceOf(AzureDevOpsPRDriver::class);
});

test('it uses mail driver when configured', function () {
    config(['paladin.pr_provider' => 'mail']);

    $manager = new PullRequestManager;
    $driver = $manager->getDriver();

    expect($driver)->toBeInstanceOf(MailNotificationDriver::class);
});

test('it throws exception for unknown provider', function () {
    config(['paladin.pr_provider' => 'unknown']);

    $manager = new PullRequestManager;
    $manager->createPullRequest('test-branch', 'Test PR', 'Test body');
})->throws(RuntimeException::class, 'Unknown PR provider');

test('it uses default base branch', function () {
    config(['paladin.pr_provider' => 'github']);
    config(['paladin.git.default_branch' => 'develop']);

    $mockDriver = Mockery::mock(PullRequestDriver::class);
    $mockDriver->shouldReceive('createPullRequest')
        ->with('test-branch', 'Test PR', 'Test body', 'develop')
        ->once()
        ->andReturn('http://github.com/pr/1');

    $manager = new PullRequestManager;
    $manager->setDriver($mockDriver);

    $result = $manager->createPullRequest('test-branch', 'Test PR', 'Test body');

    expect($result)->toBe('http://github.com/pr/1');
});

test('it can get first available driver', function () {
    // Configure GitHub as available
    config(['paladin.providers.github.token' => 'test-token']);

    $manager = new PullRequestManager;
    $driver = $manager->getFirstAvailableDriver();

    expect($driver)->not->toBeNull();
});

test('it returns null when no driver configured', function () {
    // Clear all driver configurations
    config(['paladin.providers.github.token' => null]);
    config(['paladin.providers.azure-devops.token' => null]);
    config(['paladin.providers.mail.to' => null]);

    $manager = new PullRequestManager;
    $driver = $manager->getFirstAvailableDriver();

    expect($driver)->toBeNull();
});
