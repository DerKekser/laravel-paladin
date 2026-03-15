<?php

namespace Kekser\LaravelPaladin\Exceptions;

/**
 * Exception thrown when AI provider request times out
 * This exception is retryable
 */
class AiTimeoutException extends AiProviderException
{
    public function isRetryable(): bool
    {
        return true;
    }

    public static function fromException(\Throwable $exception, array $context = []): self
    {
        return new self(
            message: 'AI provider request timed out: '.$exception->getMessage(),
            code: 408,
            previous: $exception,
            context: $context
        );
    }
}
