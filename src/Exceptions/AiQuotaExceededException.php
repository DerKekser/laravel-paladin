<?php

namespace Kekser\LaravelPaladin\Exceptions;

/**
 * Exception thrown when AI provider quota is exceeded
 * This exception is NOT retryable - requires quota increase or plan upgrade
 */
class AiQuotaExceededException extends AiProviderException
{
    public function isRetryable(): bool
    {
        return false;
    }

    public static function fromException(\Throwable $exception, array $context = []): self
    {
        return new self(
            message: 'AI provider quota exceeded: '.$exception->getMessage(),
            code: 429,
            previous: $exception,
            context: $context
        );
    }
}
