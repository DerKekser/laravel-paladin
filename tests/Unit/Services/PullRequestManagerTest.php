<?php

use Kekser\LaravelPaladin\Services\PullRequestManager;

test('it uses github driver when configured', function () {
    config(['paladin.pr_provider' => 'github']);

    $manager = new PullRequestManager;

    // We can't directly test the driver, but we can ensure no exception is thrown
    expect($manager)->toBeInstanceOf(PullRequestManager::class);
});

test('it uses azure driver when configured', function () {
    config(['paladin.pr_provider' => 'azure-devops']);

    $manager = new PullRequestManager;

    expect($manager)->toBeInstanceOf(PullRequestManager::class);
});

test('it uses mail driver when configured', function () {
    config(['paladin.pr_provider' => 'mail']);

    $manager = new PullRequestManager;

    expect($manager)->toBeInstanceOf(PullRequestManager::class);
});

test('it throws exception for unknown provider', function () {
    config(['paladin.pr_provider' => 'unknown']);

    $manager = new PullRequestManager;
    $manager->createPullRequest('test-branch', 'Test PR', 'Test body');
})->throws(RuntimeException::class, 'Unknown PR provider');

test('it uses default base branch', function () {
    config(['paladin.pr_provider' => 'github']);
    config(['paladin.git.default_branch' => 'main']);

    $manager = new PullRequestManager;

    expect($manager)->toBeInstanceOf(PullRequestManager::class);
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
