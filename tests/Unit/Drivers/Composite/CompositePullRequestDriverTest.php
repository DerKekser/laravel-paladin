<?php

use Kekser\LaravelPaladin\Contracts\PullRequestDriver;
use Kekser\LaravelPaladin\Pr\Drivers\Composite\CompositePullRequestDriver;

afterEach(function () {
    Mockery::close();
});

test('it delegates to all drivers', function () {
    $driver1 = Mockery::mock(PullRequestDriver::class);
    $driver2 = Mockery::mock(PullRequestDriver::class);

    $driver1->shouldReceive('createPullRequest')
        ->with('branch', 'title', 'body', 'main')
        ->once()
        ->andReturn('url1');

    $driver2->shouldReceive('createPullRequest')
        ->with('branch', 'title', 'body', 'main')
        ->once()
        ->andReturn('url2');

    $composite = new CompositePullRequestDriver([$driver1, $driver2]);
    $result = $composite->createPullRequest('branch', 'title', 'body', 'main');

    expect($result)->toBe('url1');
});

test('it returns first successful url', function () {
    $driver1 = Mockery::mock(PullRequestDriver::class);
    $driver2 = Mockery::mock(PullRequestDriver::class);

    $driver1->shouldReceive('createPullRequest')->andReturn(null);
    $driver2->shouldReceive('createPullRequest')->andReturn('url2');

    $composite = new CompositePullRequestDriver([$driver1, $driver2]);
    $result = $composite->createPullRequest('branch', 'title', 'body', 'main');

    expect($result)->toBe('url2');
});

test('is configured returns true only if all drivers are configured', function () {
    $driver1 = Mockery::mock(PullRequestDriver::class);
    $driver2 = Mockery::mock(PullRequestDriver::class);

    $driver1->shouldReceive('isConfigured')->andReturn(true);
    $driver2->shouldReceive('isConfigured')->andReturn(true);

    $composite = new CompositePullRequestDriver([$driver1, $driver2]);
    expect($composite->isConfigured())->toBeTrue();

    $driver3 = Mockery::mock(PullRequestDriver::class);
    $driver3->shouldReceive('isConfigured')->andReturn(false);

    $composite2 = new CompositePullRequestDriver([$driver1, $driver3]);
    expect($composite2->isConfigured())->toBeFalse();
});

test('is configured returns false if no drivers', function () {
    $composite = new CompositePullRequestDriver([]);
    expect($composite->isConfigured())->toBeFalse();
});

test('it collects errors from all drivers', function () {
    $driver1 = Mockery::mock(PullRequestDriver::class);
    $driver2 = Mockery::mock(PullRequestDriver::class);

    $driver1->shouldReceive('getConfigurationErrors')->andReturn(['error1']);
    $driver2->shouldReceive('getConfigurationErrors')->andReturn(['error2', 'error3']);

    $composite = new CompositePullRequestDriver([$driver1, $driver2]);
    $errors = $composite->getConfigurationErrors();

    expect($errors)->toBe(['error1', 'error2', 'error3']);
});
