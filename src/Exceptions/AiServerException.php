<?php

namespace Kekser\LaravelPaladin\Exceptions;

/**
 * Exception thrown when AI provider has server errors (HTTP 500/503)
 * This exception is retryable with shorter backoff
 */
class AiServerException extends AiProviderException
{
    public function isRetryable(): bool
    {
        return true;
    }

    public static function fromException(\Throwable $exception, array $context = []): self
    {
        return new self(
            message: 'AI provider server error: '.$exception->getMessage(),
            code: $exception->getCode() ?: 500,
            previous: $exception,
            context: $context
        );
    }
}
