<?php

namespace Kekser\LaravelPaladin\Exceptions;

/**
 * Exception thrown when AI provider rate limit is hit (HTTP 429)
 * This exception is retryable with exponential backoff
 */
class AiRateLimitException extends AiProviderException
{
    public function isRetryable(): bool
    {
        return true;
    }

    public static function fromException(\Throwable $exception, array $context = []): self
    {
        return new self(
            message: 'AI provider rate limit exceeded: '.$exception->getMessage(),
            code: 429,
            previous: $exception,
            context: $context
        );
    }
}
