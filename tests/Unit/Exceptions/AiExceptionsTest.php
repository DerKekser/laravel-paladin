<?php

namespace Kekser\LaravelPaladin\Tests\Unit\Exceptions;

use Kekser\LaravelPaladin\Exceptions\AiAuthenticationException;
use Kekser\LaravelPaladin\Exceptions\AiProviderException;
use Kekser\LaravelPaladin\Exceptions\AiQuotaExceededException;
use Kekser\LaravelPaladin\Exceptions\AiRateLimitException;
use Kekser\LaravelPaladin\Exceptions\AiServerException;
use Kekser\LaravelPaladin\Exceptions\AiTimeoutException;
use Kekser\LaravelPaladin\Tests\TestCase;

class AiExceptionsTest extends TestCase
{
    /** @test */
    public function ai_provider_exception_stores_context()
    {
        $context = ['key' => 'value', 'provider' => 'gemini'];
        $exception = new AiProviderException('Test message', 0, null, $context);

        $this->assertEquals($context, $exception->getContext());
    }

    /** @test */
    public function ai_provider_exception_is_not_retryable_by_default()
    {
        $exception = new AiProviderException('Test message');

        $this->assertFalse($exception->isRetryable());
    }

    /** @test */
    public function rate_limit_exception_is_retryable()
    {
        $exception = new AiRateLimitException('Rate limit exceeded');

        $this->assertTrue($exception->isRetryable());
    }

    /** @test */
    public function rate_limit_exception_can_be_created_from_exception()
    {
        $original = new \Exception('Too many requests');
        $context = ['provider' => 'openai'];

        $exception = AiRateLimitException::fromException($original, $context);

        $this->assertInstanceOf(AiRateLimitException::class, $exception);
        $this->assertEquals(429, $exception->getCode());
        $this->assertEquals($context, $exception->getContext());
        $this->assertStringContainsString('rate limit exceeded', $exception->getMessage());
    }

    /** @test */
    public function server_exception_is_retryable()
    {
        $exception = new AiServerException('Internal server error');

        $this->assertTrue($exception->isRetryable());
    }

    /** @test */
    public function timeout_exception_is_retryable()
    {
        $exception = new AiTimeoutException('Request timeout');

        $this->assertTrue($exception->isRetryable());
    }

    /** @test */
    public function authentication_exception_is_not_retryable()
    {
        $exception = new AiAuthenticationException('Unauthorized');

        $this->assertFalse($exception->isRetryable());
    }

    /** @test */
    public function quota_exceeded_exception_is_not_retryable()
    {
        $exception = new AiQuotaExceededException('Quota exceeded');

        $this->assertFalse($exception->isRetryable());
    }

    /** @test */
    public function all_exceptions_extend_ai_provider_exception()
    {
        $exceptions = [
            new AiRateLimitException('test'),
            new AiServerException('test'),
            new AiTimeoutException('test'),
            new AiAuthenticationException('test'),
            new AiQuotaExceededException('test'),
        ];

        foreach ($exceptions as $exception) {
            $this->assertInstanceOf(AiProviderException::class, $exception);
        }
    }

    /** @test */
    public function exceptions_preserve_previous_exception()
    {
        $original = new \Exception('Original error');
        $exception = new AiProviderException('Wrapped error', 0, $original);

        $this->assertSame($original, $exception->getPrevious());
    }

    /** @test */
    public function exceptions_preserve_error_messages()
    {
        $message = 'Custom error message';
        $exception = new AiProviderException($message);

        $this->assertEquals($message, $exception->getMessage());
    }
}
