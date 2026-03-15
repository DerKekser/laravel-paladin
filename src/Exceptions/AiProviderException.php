<?php

namespace Kekser\LaravelPaladin\Exceptions;

use Exception;

/**
 * Base exception for AI provider errors
 */
class AiProviderException extends Exception
{
    protected array $context = [];

    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    public function getContext(): array
    {
        return $this->context;
    }

    public function isRetryable(): bool
    {
        return false;
    }
}
