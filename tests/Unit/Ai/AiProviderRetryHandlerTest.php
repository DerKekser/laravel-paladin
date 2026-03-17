<?php

use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Ai\AiProviderRetryHandler;
use Kekser\LaravelPaladin\Exceptions\AiAuthenticationException;
use Kekser\LaravelPaladin\Exceptions\AiProviderException;
use Kekser\LaravelPaladin\Exceptions\AiQuotaExceededException;
use Kekser\LaravelPaladin\Exceptions\AiRateLimitException;
use Kekser\LaravelPaladin\Exceptions\AiServerException;
use Kekser\LaravelPaladin\Exceptions\AiTimeoutException;

beforeEach(function () {
    $this->handler = new AiProviderRetryHandler;
    $this->handler->setSleepMultiplier(0); // Disable sleep in tests
    Log::spy();
});

/**
 * Create a test exception with a specific code.
 */
function createExceptionWithCode(string $message, int $code): Exception
{
    return new class($message, $code) extends Exception {};
}

test('it executes callable successfully on first attempt', function () {
    $result = $this->handler->executeWithRetry(function () {
        return 'success';
    });

    expect($result)->toBe('success');
});

test('it retries on rate limit error with http 429', function () {
    $attempt = 0;

    $result = $this->handler->executeWithRetry(function () use (&$attempt) {
        $attempt++;
        if ($attempt < 2) {
            throw createExceptionWithCode('Rate limit exceeded', 429);
        }

        return 'success';
    });

    expect($result)->toBe('success');
    expect($attempt)->toBe(2);
});

test('it retries on rate limit error with message', function () {
    $attempt = 0;

    $result = $this->handler->executeWithRetry(function () use (&$attempt) {
        $attempt++;
        if ($attempt < 2) {
            throw new Exception('API rate limit exceeded');
        }

        return 'success';
    });

    expect($result)->toBe('success');
    expect($attempt)->toBe(2);
});

test('it throws after max rate limit retries', function () {
    $attempt = 0;
    $this->handler->executeWithRetry(function () use (&$attempt) {
        $attempt++;
        throw createExceptionWithCode('Rate limit exceeded', 429);
    });
})->throws(AiRateLimitException::class);

test('it retries on server errors', function () {
    $attempt = 0;

    $result = $this->handler->executeWithRetry(function () use (&$attempt) {
        $attempt++;
        if ($attempt < 2) {
            throw createExceptionWithCode('Internal server error', 500);
        }

        return 'success';
    });

    expect($result)->toBe('success');
    expect($attempt)->toBe(2);
});

test('it detects server errors by status code', function () {
    $statusCodes = [500, 502, 503, 504];

    foreach ($statusCodes as $code) {
        $attempt = 0;

        $result = $this->handler->executeWithRetry(function () use (&$attempt, $code) {
            $attempt++;
            if ($attempt < 2) {
                throw createExceptionWithCode('Server error', $code);
            }

            return 'success';
        });

        expect($result)->toBe('success');
    }
});

test('it retries on timeout errors', function () {
    $attempt = 0;

    $result = $this->handler->executeWithRetry(function () use (&$attempt) {
        $attempt++;
        if ($attempt < 2) {
            throw new Exception('Request timeout');
        }

        return 'success';
    });

    expect($result)->toBe('success');
    expect($attempt)->toBe(2);
});

test('it detects timeout by http 408', function () {
    $attempt = 0;

    $result = $this->handler->executeWithRetry(function () use (&$attempt) {
        $attempt++;
        if ($attempt < 2) {
            throw createExceptionWithCode('Timeout', 408);
        }

        return 'success';
    });

    expect($result)->toBe('success');
});

test('it does not retry authentication errors', function () {
    $attempt = 0;
    try {
        $this->handler->executeWithRetry(function () use (&$attempt) {
            $attempt++;
            throw createExceptionWithCode('Unauthorized', 401);
        });
    } catch (AiAuthenticationException $e) {
        // Expected
    }

    // Should only attempt once
    expect($attempt)->toBe(1);
});

test('it detects authentication errors by status code', function () {
    $this->handler->executeWithRetry(function () {
        throw createExceptionWithCode('Error', 403);
    });
})->throws(AiAuthenticationException::class);

test('it detects authentication errors by message', function () {
    $this->handler->executeWithRetry(function () {
        throw new Exception('Invalid API key provided');
    });
})->throws(AiAuthenticationException::class);

test('it does not retry quota exceeded errors', function () {
    $attempt = 0;
    try {
        $this->handler->executeWithRetry(function () use (&$attempt) {
            $attempt++;
            throw new Exception('Quota exceeded for this billing period');
        });
    } catch (AiQuotaExceededException $e) {
        // Expected
    }

    expect($attempt)->toBe(1);
});

test('it converts generic exceptions to ai provider exception', function () {
    $this->handler->executeWithRetry(function () {
        throw new Exception('Unknown error');
    });
})->throws(AiProviderException::class, 'AI provider error: Unknown error');

test('it respects max retries for rate limits', function () {
    $attempt = 0;

    try {
        $this->handler->executeWithRetry(function () use (&$attempt) {
            $attempt++;
            throw new Exception('rate limit exceeded');
        });
    } catch (AiRateLimitException $e) {
        // Expected
    }

    // MAX_RETRIES is 5, so should attempt 1 initial + 5 retries = 6 total
    expect($attempt)->toBe(6);
});

test('it respects max retries for server errors', function () {
    $attempt = 0;

    try {
        $this->handler->executeWithRetry(function () use (&$attempt) {
            $attempt++;
            throw createExceptionWithCode('Internal server error', 500);
        });
    } catch (AiServerException $e) {
        // Expected
    }

    // SERVER_ERROR_MAX_RETRIES is 3, so 1 initial + 3 retries = 4 total
    expect($attempt)->toBe(4);
});

test('it respects max retries for timeouts', function () {
    $attempt = 0;

    try {
        $this->handler->executeWithRetry(function () use (&$attempt) {
            $attempt++;
            throw new Exception('Request timed out');
        });
    } catch (AiTimeoutException $e) {
        // Expected
    }

    // TIMEOUT_MAX_RETRIES is 3, so 1 initial + 3 retries = 4 total
    expect($attempt)->toBe(4);
});

test('it includes context in exception', function () {
    $context = [
        'provider' => 'gemini',
        'model' => 'gemini-2.0-flash',
        'operation' => 'analyze',
    ];

    try {
        $this->handler->executeWithRetry(function () {
            throw new Exception('API key invalid');
        }, $context);
    } catch (AiAuthenticationException $e) {
        expect($e->getContext())->toBe($context);
    }
});

test('it logs retry attempts', function () {
    $attempt = 0;

    $this->handler->executeWithRetry(function () use (&$attempt) {
        $attempt++;
        if ($attempt < 3) {
            throw new Exception('rate limit exceeded');
        }

        return 'success';
    });

    // Should log warning for each retry
    Log::shouldHaveReceived('warning')
        ->times(2); // 2 retries after initial attempt
});

test('it logs non retryable errors', function () {
    try {
        $this->handler->executeWithRetry(function () {
            throw new Exception('Unauthorized');
        });
    } catch (AiAuthenticationException $e) {
        // Expected
    }

    Log::shouldHaveReceived('error')
        ->once()
        ->with('[Paladin][AI] Non-retryable AI provider error', Mockery::any());
});

test('it logs max retries exceeded', function () {
    try {
        $this->handler->executeWithRetry(function () {
            throw new Exception('timeout');
        });
    } catch (AiTimeoutException $e) {
        // Expected
    }

    Log::shouldHaveReceived('error')
        ->once()
        ->with('[Paladin][AI] Max retries exceeded for AI provider', Mockery::any());
});

test('it detects various rate limit messages', function () {
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
                throw new Exception($message);
            }

            return 'success';
        });

        expect($result)->toBe('success', "Failed for message: {$message}");
    }
});

test('it detects various server error messages', function () {
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
                throw new Exception($message);
            }

            return 'success';
        });

        expect($result)->toBe('success', "Failed for message: {$message}");
    }
});

test('it detects various timeout messages', function () {
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
                throw new Exception($message);
            }

            return 'success';
        });

        expect($result)->toBe('success', "Failed for message: {$message}");
    }
});

test('it detects various authentication messages', function () {
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
                throw new Exception($message);
            });
            $this->fail("Expected AiAuthenticationException for message: {$message}");
        } catch (AiAuthenticationException $e) {
            // Expected
            expect($e)->toBeInstanceOf(AiAuthenticationException::class);
        }
    }
});
