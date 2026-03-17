<?php

use Kekser\LaravelPaladin\Exceptions\AiAuthenticationException;
use Kekser\LaravelPaladin\Exceptions\AiProviderException;
use Kekser\LaravelPaladin\Exceptions\AiQuotaExceededException;
use Kekser\LaravelPaladin\Exceptions\AiRateLimitException;
use Kekser\LaravelPaladin\Exceptions\AiServerException;
use Kekser\LaravelPaladin\Exceptions\AiTimeoutException;

test('ai provider exception stores context', function () {
    $context = ['key' => 'value', 'provider' => 'gemini'];
    $exception = new AiProviderException('Test message', 0, null, $context);

    expect($exception->getContext())->toBe($context);
});

test('ai provider exception is not retryable by default', function () {
    $exception = new AiProviderException('Test message');

    expect($exception->isRetryable())->toBeFalse();
});

test('rate limit exception is retryable', function () {
    $exception = new AiRateLimitException('Rate limit exceeded');

    expect($exception->isRetryable())->toBeTrue();
});

test('rate limit exception can be created from exception', function () {
    $original = new Exception('Too many requests');
    $context = ['provider' => 'openai'];

    $exception = AiRateLimitException::fromException($original, $context);

    expect($exception)->toBeInstanceOf(AiRateLimitException::class);
    expect($exception->getCode())->toBe(429);
    expect($exception->getContext())->toBe($context);
    expect($exception->getMessage())->toContain('rate limit exceeded');
});

test('server exception is retryable', function () {
    $exception = new AiServerException('Internal server error');

    expect($exception->isRetryable())->toBeTrue();
});

test('timeout exception is retryable', function () {
    $exception = new AiTimeoutException('Request timeout');

    expect($exception->isRetryable())->toBeTrue();
});

test('authentication exception is not retryable', function () {
    $exception = new AiAuthenticationException('Unauthorized');

    expect($exception->isRetryable())->toBeFalse();
});

test('quota exceeded exception is not retryable', function () {
    $exception = new AiQuotaExceededException('Quota exceeded');

    expect($exception->isRetryable())->toBeFalse();
});

test('all exceptions extend ai provider exception', function () {
    $exceptions = [
        new AiRateLimitException('test'),
        new AiServerException('test'),
        new AiTimeoutException('test'),
        new AiAuthenticationException('test'),
        new AiQuotaExceededException('test'),
    ];

    foreach ($exceptions as $exception) {
        expect($exception)->toBeInstanceOf(AiProviderException::class);
    }
});

test('exceptions preserve previous exception', function () {
    $original = new Exception('Original error');
    $exception = new AiProviderException('Wrapped error', 0, $original);

    expect($exception->getPrevious())->toBe($original);
});

test('exceptions preserve error messages', function () {
    $message = 'Custom error message';
    $exception = new AiProviderException($message);

    expect($exception->getMessage())->toBe($message);
});
