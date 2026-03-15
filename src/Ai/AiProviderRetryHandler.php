<?php

namespace Kekser\LaravelPaladin\Ai;

use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Exceptions\AiAuthenticationException;
use Kekser\LaravelPaladin\Exceptions\AiProviderException;
use Kekser\LaravelPaladin\Exceptions\AiQuotaExceededException;
use Kekser\LaravelPaladin\Exceptions\AiRateLimitException;
use Kekser\LaravelPaladin\Exceptions\AiServerException;
use Kekser\LaravelPaladin\Exceptions\AiTimeoutException;

class AiProviderRetryHandler
{
    // Rate limit retry configuration
    private const MAX_RETRIES = 5;

    private const BASE_DELAY_SECONDS = 2;

    private const MAX_DELAY_SECONDS = 32;

    private const MAX_TOTAL_WAIT_SECONDS = 60;

    private const JITTER_PERCENTAGE = 0.2; // ±20% randomization

    // Server error retry configuration (shorter backoff)
    private const SERVER_ERROR_MAX_RETRIES = 3;

    private const SERVER_ERROR_BASE_DELAY = 1;

    // Timeout retry configuration
    private const TIMEOUT_MAX_RETRIES = 3;

    private const TIMEOUT_BASE_DELAY = 2;

    /**
     * Sleep multiplier for testing (0 = no sleep, 1 = normal sleep)
     */
    protected float $sleepMultiplier = 1.0;

    /**
     * Execute a callable with retry logic for AI provider errors
     *
     * @param  callable  $callable  The function to execute
     * @param  array  $context  Additional context for logging
     * @return mixed The result of the callable
     *
     * @throws AiProviderException
     */
    public function executeWithRetry(callable $callable, array $context = []): mixed
    {
        $attempt = 0;
        $totalWaitTime = 0;
        $lastException = null;

        while (true) {
            try {
                $attempt++;

                if ($attempt > 1) {
                    Log::info('[Paladin][AI] Retrying AI provider call', array_merge($context, [
                        'attempt' => $attempt,
                    ]));
                }

                return $callable();
            } catch (\Throwable $e) {
                $lastException = $e;

                // Convert to our custom exception types
                $customException = $this->convertException($e, $context);

                // Determine if we should retry
                if (! $customException->isRetryable()) {
                    Log::error('[Paladin][AI] Non-retryable AI provider error', array_merge($context, [
                        'error' => $customException->getMessage(),
                        'exception_class' => get_class($customException),
                    ]));
                    throw $customException;
                }

                // Get retry configuration based on exception type
                $retryConfig = $this->getRetryConfig($customException);

                // Check if we've exceeded max retries
                if ($attempt > $retryConfig['max_retries']) {
                    Log::error('[Paladin][AI] Max retries exceeded for AI provider', array_merge($context, [
                        'attempts' => $attempt - 1,
                        'total_wait_seconds' => round($totalWaitTime, 2),
                        'error' => $customException->getMessage(),
                        'exception_class' => get_class($customException),
                    ]));
                    throw $customException;
                }

                // Calculate delay with exponential backoff and jitter
                $delay = $this->calculateDelay($attempt, $retryConfig);

                // Check if we've exceeded max total wait time (only if actually sleeping)
                if ($this->sleepMultiplier > 0 && $totalWaitTime + $delay > self::MAX_TOTAL_WAIT_SECONDS) {
                    Log::error('[Paladin][AI] Max total wait time exceeded', array_merge($context, [
                        'attempts' => $attempt,
                        'total_wait_seconds' => round($totalWaitTime, 2),
                        'error' => $customException->getMessage(),
                    ]));
                    throw $customException;
                }

                Log::warning('[Paladin][AI] '.$this->getRetryMessage($customException), array_merge($context, [
                    'attempt' => $attempt,
                    'max_retries' => $retryConfig['max_retries'],
                    'delay_seconds' => round($delay, 2),
                    'total_wait_seconds' => round($totalWaitTime, 2),
                    'error' => $customException->getMessage(),
                ]));

                // Wait before retrying
                $this->sleep($delay);
                // Only track actual wait time
                if ($this->sleepMultiplier > 0) {
                    $totalWaitTime += $delay * $this->sleepMultiplier;
                }
            }
        }
    }

    /**
     * Convert generic exception to custom AI exception type
     */
    private function convertException(\Throwable $e, array $context): AiProviderException
    {
        $message = strtolower($e->getMessage());
        $code = $e->getCode();

        // Check for rate limit indicators
        if ($this->isRateLimitError($message, $code)) {
            return AiRateLimitException::fromException($e, $context);
        }

        // Check for quota exceeded
        if ($this->isQuotaExceededError($message, $code)) {
            return AiQuotaExceededException::fromException($e, $context);
        }

        // Check for authentication errors
        if ($this->isAuthenticationError($message, $code)) {
            return AiAuthenticationException::fromException($e, $context);
        }

        // Check for server errors
        if ($this->isServerError($message, $code)) {
            return AiServerException::fromException($e, $context);
        }

        // Check for timeout errors
        if ($this->isTimeoutError($message, $code)) {
            return AiTimeoutException::fromException($e, $context);
        }

        // Default to generic AI provider exception (not retryable)
        return new AiProviderException(
            message: 'AI provider error: '.$e->getMessage(),
            code: $code,
            previous: $e,
            context: $context
        );
    }

    private function isRateLimitError(string $message, int $code): bool
    {
        return $code === 429 ||
               str_contains($message, 'rate limit') ||
               str_contains($message, 'too many requests') ||
               str_contains($message, 'rate_limit_exceeded');
    }

    private function isQuotaExceededError(string $message, int $code): bool
    {
        return str_contains($message, 'quota exceeded') ||
               str_contains($message, 'insufficient_quota') ||
               str_contains($message, 'quota_exceeded') ||
               str_contains($message, 'billing');
    }

    private function isAuthenticationError(string $message, int $code): bool
    {
        return in_array($code, [401, 403]) ||
               str_contains($message, 'unauthorized') ||
               str_contains($message, 'api key') ||
               str_contains($message, 'authentication') ||
               str_contains($message, 'invalid_api_key') ||
               str_contains($message, 'permission denied');
    }

    private function isServerError(string $message, int $code): bool
    {
        return in_array($code, [500, 502, 503, 504]) ||
               str_contains($message, 'internal error') ||
               str_contains($message, 'internal_error') ||
               str_contains($message, 'service unavailable') ||
               str_contains($message, 'bad gateway') ||
               str_contains($message, 'gateway timeout');
    }

    private function isTimeoutError(string $message, int $code): bool
    {
        return $code === 408 ||
               str_contains($message, 'timeout') ||
               str_contains($message, 'timed out') ||
               str_contains($message, 'deadline exceeded');
    }

    /**
     * Get retry configuration based on exception type
     */
    private function getRetryConfig(AiProviderException $exception): array
    {
        if ($exception instanceof AiRateLimitException) {
            return [
                'max_retries' => self::MAX_RETRIES,
                'base_delay' => self::BASE_DELAY_SECONDS,
                'max_delay' => self::MAX_DELAY_SECONDS,
            ];
        }

        if ($exception instanceof AiServerException) {
            return [
                'max_retries' => self::SERVER_ERROR_MAX_RETRIES,
                'base_delay' => self::SERVER_ERROR_BASE_DELAY,
                'max_delay' => 8, // Max 8 seconds for server errors
            ];
        }

        if ($exception instanceof AiTimeoutException) {
            return [
                'max_retries' => self::TIMEOUT_MAX_RETRIES,
                'base_delay' => self::TIMEOUT_BASE_DELAY,
                'max_delay' => 8, // Max 8 seconds for timeouts
            ];
        }

        // Default config (shouldn't reach here for non-retryable)
        return [
            'max_retries' => 0,
            'base_delay' => 0,
            'max_delay' => 0,
        ];
    }

    /**
     * Calculate delay with exponential backoff and jitter
     */
    private function calculateDelay(int $attempt, array $config): float
    {
        // Exponential backoff: base_delay * 2^(attempt-1)
        $exponentialDelay = $config['base_delay'] * pow(2, $attempt - 1);

        // Cap at max delay
        $delay = min($exponentialDelay, $config['max_delay']);

        // Add jitter (±20% randomization to prevent thundering herd)
        $jitterRange = $delay * self::JITTER_PERCENTAGE;
        $randMax = mt_getrandmax();
        $jitter = $randMax > 0
            ? (mt_rand() / $randMax) * ($jitterRange * 2) - $jitterRange
            : 0;

        return max(0.1, $delay + $jitter); // Ensure minimum 0.1s delay
    }

    /**
     * Get human-readable retry message based on exception type
     */
    private function getRetryMessage(AiProviderException $exception): string
    {
        if ($exception instanceof AiRateLimitException) {
            return 'Rate limit encountered - retrying with exponential backoff';
        }

        if ($exception instanceof AiServerException) {
            return 'Server error encountered - retrying with shorter backoff';
        }

        if ($exception instanceof AiTimeoutException) {
            return 'Timeout encountered - retrying';
        }

        return 'AI provider error - retrying';
    }

    /**
     * Sleep for the specified delay (can be overridden in tests).
     */
    protected function sleep(float $delay): void
    {
        if ($this->sleepMultiplier > 0) {
            usleep((int) ($delay * $this->sleepMultiplier * 1_000_000));
        }
    }

    /**
     * Set the sleep multiplier (for testing).
     */
    public function setSleepMultiplier(float $multiplier): self
    {
        $this->sleepMultiplier = $multiplier;

        return $this;
    }
}
