<?php

namespace Kekser\LaravelPaladin\Tests\Unit\Ai;

use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Ai\AiProviderRetryHandler;
use Kekser\LaravelPaladin\Exceptions\AiAuthenticationException;
use Kekser\LaravelPaladin\Exceptions\AiProviderException;
use Kekser\LaravelPaladin\Exceptions\AiQuotaExceededException;
use Kekser\LaravelPaladin\Exceptions\AiRateLimitException;
use Kekser\LaravelPaladin\Exceptions\AiServerException;
use Kekser\LaravelPaladin\Exceptions\AiTimeoutException;
use Kekser\LaravelPaladin\Tests\TestCase;

class AiProviderRetryHandlerTest extends TestCase
{
    protected AiProviderRetryHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = new AiProviderRetryHandler;
        $this->handler->setSleepMultiplier(0); // Disable sleep in tests
        Log::spy();
    }

    /**
     * Create a test exception with a specific code.
     */
    protected function createExceptionWithCode(string $message, int $code): \Exception
    {
        return new class($message, $code) extends \Exception {};
    }

    /** @test */
    public function it_executes_callable_successfully_on_first_attempt()
    {
        $result = $this->handler->executeWithRetry(function () {
            return 'success';
        });

        $this->assertEquals('success', $result);
    }

    /** @test */
    public function it_retries_on_rate_limit_error_with_http_429()
    {
        $attempt = 0;

        $result = $this->handler->executeWithRetry(function () use (&$attempt) {
            $attempt++;
            if ($attempt < 2) {
                throw $this->createExceptionWithCode('Rate limit exceeded', 429);
            }

            return 'success';
        });

        $this->assertEquals('success', $result);
        $this->assertEquals(2, $attempt);
    }

    /** @test */
    public function it_retries_on_rate_limit_error_with_message()
    {
        $attempt = 0;

        $result = $this->handler->executeWithRetry(function () use (&$attempt) {
            $attempt++;
            if ($attempt < 2) {
                throw new \Exception('API rate limit exceeded');
            }

            return 'success';
        });

        $this->assertEquals('success', $result);
        $this->assertEquals(2, $attempt);
    }

    /** @test */
    public function it_throws_after_max_rate_limit_retries()
    {
        $this->expectException(AiRateLimitException::class);

        $attempt = 0;
        $this->handler->executeWithRetry(function () use (&$attempt) {
            $attempt++;
            throw $this->createExceptionWithCode('Rate limit exceeded', 429);
        });
    }

    /** @test */
    public function it_retries_on_server_errors()
    {
        $attempt = 0;

        $result = $this->handler->executeWithRetry(function () use (&$attempt) {
            $attempt++;
            if ($attempt < 2) {
                throw $this->createExceptionWithCode('Internal server error', 500);
            }

            return 'success';
        });

        $this->assertEquals('success', $result);
        $this->assertEquals(2, $attempt);
    }

    /** @test */
    public function it_detects_server_errors_by_status_code()
    {
        $statusCodes = [500, 502, 503, 504];

        foreach ($statusCodes as $code) {
            $attempt = 0;

            $result = $this->handler->executeWithRetry(function () use (&$attempt, $code) {
                $attempt++;
                if ($attempt < 2) {
                    throw $this->createExceptionWithCode('Server error', $code);
                }

                return 'success';
            });

            $this->assertEquals('success', $result);
        }
    }

    /** @test */
    public function it_retries_on_timeout_errors()
    {
        $attempt = 0;

        $result = $this->handler->executeWithRetry(function () use (&$attempt) {
            $attempt++;
            if ($attempt < 2) {
                throw new \Exception('Request timeout');
            }

            return 'success';
        });

        $this->assertEquals('success', $result);
        $this->assertEquals(2, $attempt);
    }

    /** @test */
    public function it_detects_timeout_by_http_408()
    {
        $attempt = 0;

        $result = $this->handler->executeWithRetry(function () use (&$attempt) {
            $attempt++;
            if ($attempt < 2) {
                throw $this->createExceptionWithCode('Timeout', 408);
            }

            return 'success';
        });

        $this->assertEquals('success', $result);
    }

    /** @test */
    public function it_does_not_retry_authentication_errors()
    {
        $this->expectException(AiAuthenticationException::class);

        $attempt = 0;
        $this->handler->executeWithRetry(function () use (&$attempt) {
            $attempt++;
            throw $this->createExceptionWithCode('Unauthorized', 401);
        });

        // Should only attempt once
        $this->assertEquals(1, $attempt);
    }

    /** @test */
    public function it_detects_authentication_errors_by_status_code()
    {
        $this->expectException(AiAuthenticationException::class);

        $this->handler->executeWithRetry(function () {
            throw $this->createExceptionWithCode('Error', 403);
        });
    }

    /** @test */
    public function it_detects_authentication_errors_by_message()
    {
        $this->expectException(AiAuthenticationException::class);

        $this->handler->executeWithRetry(function () {
            throw new \Exception('Invalid API key provided');
        });
    }

    /** @test */
    public function it_does_not_retry_quota_exceeded_errors()
    {
        $this->expectException(AiQuotaExceededException::class);

        $attempt = 0;
        $this->handler->executeWithRetry(function () use (&$attempt) {
            $attempt++;
            throw new \Exception('Quota exceeded for this billing period');
        });

        $this->assertEquals(1, $attempt);
    }

    /** @test */
    public function it_converts_generic_exceptions_to_ai_provider_exception()
    {
        $this->expectException(AiProviderException::class);
        $this->expectExceptionMessage('AI provider error: Unknown error');

        $this->handler->executeWithRetry(function () {
            throw new \Exception('Unknown error');
        });
    }

    /** @test */
    public function it_respects_max_retries_for_rate_limits()
    {
        $attempt = 0;

        try {
            $this->handler->executeWithRetry(function () use (&$attempt) {
                $attempt++;
                throw new \Exception('rate limit exceeded');
            });
        } catch (AiRateLimitException $e) {
            // Expected
        }

        // MAX_RETRIES is 5, so should attempt 1 initial + 5 retries = 6 total
        $this->assertEquals(6, $attempt);
    }

    /** @test */
    public function it_respects_max_retries_for_server_errors()
    {
        $attempt = 0;

        try {
            $this->handler->executeWithRetry(function () use (&$attempt) {
                $attempt++;
                throw $this->createExceptionWithCode('Internal server error', 500);
            });
        } catch (AiServerException $e) {
            // Expected
        }

        // SERVER_ERROR_MAX_RETRIES is 3, so 1 initial + 3 retries = 4 total
        $this->assertEquals(4, $attempt);
    }

    /** @test */
    public function it_respects_max_retries_for_timeouts()
    {
        $attempt = 0;

        try {
            $this->handler->executeWithRetry(function () use (&$attempt) {
                $attempt++;
                throw new \Exception('Request timed out');
            });
        } catch (AiTimeoutException $e) {
            // Expected
        }

        // TIMEOUT_MAX_RETRIES is 3, so 1 initial + 3 retries = 4 total
        $this->assertEquals(4, $attempt);
    }

    /** @test */
    public function it_includes_context_in_exception()
    {
        $context = [
            'provider' => 'gemini',
            'model' => 'gemini-2.0-flash',
            'operation' => 'analyze',
        ];

        try {
            $this->handler->executeWithRetry(function () {
                throw new \Exception('API key invalid');
            }, $context);
        } catch (AiAuthenticationException $e) {
            $this->assertEquals($context, $e->getContext());
        }
    }

    /** @test */
    public function it_logs_retry_attempts()
    {
        $attempt = 0;

        $this->handler->executeWithRetry(function () use (&$attempt) {
            $attempt++;
            if ($attempt < 3) {
                throw new \Exception('rate limit exceeded');
            }

            return 'success';
        });

        // Should log warning for each retry
        Log::shouldHaveReceived('warning')
            ->times(2); // 2 retries after initial attempt
    }

    /** @test */
    public function it_logs_non_retryable_errors()
    {
        try {
            $this->handler->executeWithRetry(function () {
                throw new \Exception('Unauthorized');
            });
        } catch (AiAuthenticationException $e) {
            // Expected
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->with('[Paladin][AI] Non-retryable AI provider error', \Mockery::any());
    }

    /** @test */
    public function it_logs_max_retries_exceeded()
    {
        try {
            $this->handler->executeWithRetry(function () {
                throw new \Exception('timeout');
            });
        } catch (AiTimeoutException $e) {
            // Expected
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->with('[Paladin][AI] Max retries exceeded for AI provider', \Mockery::any());
    }

    /** @test */
    public function it_detects_various_rate_limit_messages()
    {
        $messages = [
            'rate limit exceeded',
            'too many requests',
            'rate_limit_exceeded',
        ];

        foreach ($messages as $message) {
            $attempt = 0;

            $result = $this->handler->executeWithRetry(function () use (&$attempt, $message) {
                $attempt++;
                if ($attempt < 2) {
                    throw new \Exception($message);
                }

                return 'success';
            });

            $this->assertEquals('success', $result, "Failed for message: {$message}");
        }
    }

    /** @test */
    public function it_detects_various_server_error_messages()
    {
        $messages = [
            'internal error occurred',
            'internal_error',
            'service unavailable',
            'bad gateway',
            'gateway timeout',
        ];

        foreach ($messages as $message) {
            $attempt = 0;

            $result = $this->handler->executeWithRetry(function () use (&$attempt, $message) {
                $attempt++;
                if ($attempt < 2) {
                    throw new \Exception($message);
                }

                return 'success';
            });

            $this->assertEquals('success', $result, "Failed for message: {$message}");
        }
    }

    /** @test */
    public function it_detects_various_timeout_messages()
    {
        $messages = [
            'timeout occurred',
            'request timed out',
            'deadline exceeded',
        ];

        foreach ($messages as $message) {
            $attempt = 0;

            $result = $this->handler->executeWithRetry(function () use (&$attempt, $message) {
                $attempt++;
                if ($attempt < 2) {
                    throw new \Exception($message);
                }

                return 'success';
            });

            $this->assertEquals('success', $result, "Failed for message: {$message}");
        }
    }

    /** @test */
    public function it_detects_various_authentication_messages()
    {
        $messages = [
            'unauthorized access',
            'invalid api key',
            'authentication failed',
            'invalid_api_key',
            'permission denied',
        ];

        foreach ($messages as $message) {
            try {
                $this->handler->executeWithRetry(function () use ($message) {
                    throw new \Exception($message);
                });
                $this->fail("Expected AiAuthenticationException for message: {$message}");
            } catch (AiAuthenticationException $e) {
                // Expected
                $this->assertInstanceOf(AiAuthenticationException::class, $e);
            }
        }
    }
}
