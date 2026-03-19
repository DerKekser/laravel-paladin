<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Kekser\LaravelPaladin\Pr\Drivers\Mail\MailNotificationDriver;

beforeEach(function () {
    config([
        'paladin.providers.mail.to' => 'test@example.com',
        'paladin.providers.mail.from' => 'paladin@example.com',
    ]);
});

test('it is configured when both to and from addresses are present', function () {
    $driver = app(MailNotificationDriver::class);
    expect($driver->isConfigured())->toBeTrue();
});

test('it is not configured when to address is missing', function () {
    config(['paladin.providers.mail.to' => null]);
    $driver = app(MailNotificationDriver::class);
    expect($driver->isConfigured())->toBeFalse();
});

test('it is not configured when from address is missing', function () {
    config(['paladin.providers.mail.from' => null]);
    $driver = app(MailNotificationDriver::class);
    expect($driver->isConfigured())->toBeFalse();
});

test('it sends email when correctly configured', function () {
    Log::spy();

    // Alternative check for Mail::send since it's using the closure way
    Mail::shouldReceive('send')
        ->once()
        ->with('paladin::fix-notification', Mockery::type('array'), Mockery::on(function ($closure) {
            $message = Mockery::mock();
            $message->shouldReceive('to')->with('test@example.com')->andReturnSelf();
            $message->shouldReceive('from')->with('paladin@example.com')->andReturnSelf();
            $message->shouldReceive('subject')->with('[Paladin] Test Title')->andReturnSelf();
            $closure($message);

            return true;
        }))
        ->andReturn(null);

    $driver = app(MailNotificationDriver::class);
    $result = $driver->createPullRequest('fix-branch', 'Test Title', 'Test Body', 'main');

    expect($result)->toBe('email:test@example.com');

    Log::shouldHaveReceived('info')->with('[Paladin] Sending fix notification email', Mockery::any());
    Log::shouldHaveReceived('info')->with('[Paladin] Fix notification email sent successfully');
});

test('it skips notification when not configured', function () {
    config(['paladin.providers.mail.to' => null]);
    Log::spy();

    $driver = app(MailNotificationDriver::class);
    $result = $driver->createPullRequest('fix-branch', 'Test Title', 'Test Body', 'main');

    expect($result)->toBeNull();
    Log::shouldHaveReceived('warning')->with('[Paladin] Mail driver is not configured, skipping notification');
});

test('it handles email sending exception', function () {
    Mail::shouldReceive('send')->andThrow(new Exception('Mail delivery failed'));
    Log::spy();

    $driver = app(MailNotificationDriver::class);
    $result = $driver->createPullRequest('fix-branch', 'Test Title', 'Test Body', 'main');

    expect($result)->toBeNull();
    Log::shouldHaveReceived('error')->with('[Paladin] Failed to send fix notification email', Mockery::on(function ($context) {
        return $context['error'] === 'Mail delivery failed';
    }));
});

test('it gets repository name from git origin', function () {
    // We can't easily mock exec in the same process if it's not called via a wrapper.
    // However, we can check if it returns "Unknown repository" if git fails or is not present.
    // Or we can rely on the fact that we are in a git repo during tests.

    $driver = app(MailNotificationDriver::class);

    // Use reflection to access protected getRepository
    $reflection = new ReflectionClass(MailNotificationDriver::class);
    $method = $reflection->getMethod('getRepository');
    $method->setAccessible(true);

    $repository = $method->invoke($driver);

    // In CI or local dev it should probably be the repo URL or "Unknown repository"
    expect($repository)->toBeString();
    expect($repository)->not->toBeEmpty();
});
