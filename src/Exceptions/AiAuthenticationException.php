<?php

namespace Kekser\LaravelPaladin\Exceptions;

/**
 * Exception thrown when AI provider authentication fails (HTTP 401/403)
 * This exception is NOT retryable - it requires configuration fix
 */
class AiAuthenticationException extends AiProviderException
{
    public function isRetryable(): bool
    {
        return false;
    }

    public static function fromException(\Throwable $exception, array $context = []): self
    {
        return new self(
            message: 'AI provider authentication failed: '.$exception->getMessage(),
            code: $exception->getCode() ?: 401,
            previous: $exception,
            context: $context
        );
    }
}
