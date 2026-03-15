<?php

namespace Kekser\LaravelPaladin\Tests\Fixtures\Helpers;

use Kekser\LaravelPaladin\Exceptions\AiAuthenticationException;
use Kekser\LaravelPaladin\Exceptions\AiQuotaExceededException;
use Kekser\LaravelPaladin\Exceptions\AiRateLimitException;
use Kekser\LaravelPaladin\Exceptions\AiServerException;
use Kekser\LaravelPaladin\Exceptions\AiTimeoutException;
use Laravel\Ai\Agent;
use Laravel\Ai\Contracts\Agent as AgentContract;
use Mockery;

trait MocksAiProviders
{
    /**
     * Mock an AI agent response.
     */
    protected function mockAiAgent(string $response): AgentContract
    {
        $agent = Mockery::mock(AgentContract::class);
        $agent->shouldReceive('prompt')
            ->andReturn($agent);
        $agent->shouldReceive('text')
            ->andReturn($response);

        return $agent;
    }

    /**
     * Mock an AI agent with structured output.
     */
    protected function mockAiAgentWithStructuredOutput(array $data): AgentContract
    {
        $agent = Mockery::mock(AgentContract::class);
        $agent->shouldReceive('prompt')
            ->andReturn($agent);
        $agent->shouldReceive('object')
            ->andReturn((object) $data);

        return $agent;
    }

    /**
     * Create a mock issue analysis response.
     */
    protected function createMockIssueAnalysis(array $overrides = []): array
    {
        return array_merge([
            'type' => 'runtime_error',
            'severity' => 'high',
            'title' => 'Division by zero in UserController',
            'message' => 'Division by zero error occurring in user statistics calculation',
            'stack_trace' => '#0 /var/www/app/Http/Controllers/UserController.php(42): calculate()',
            'affected_files' => ['app/Http/Controllers/UserController.php'],
            'suggested_fix' => 'Add validation to check divisor is not zero before division',
        ], $overrides);
    }

    /**
     * Create a mock prompt generation response.
     */
    protected function createMockPrompt(array $issueData = []): string
    {
        $issue = array_merge([
            'type' => 'runtime_error',
            'severity' => 'high',
            'title' => 'Test Issue',
            'message' => 'Test error message',
            'affected_files' => ['app/Test.php'],
        ], $issueData);

        return "Fix the following issue:\n\n"
            ."Type: {$issue['type']}\n"
            ."Severity: {$issue['severity']}\n"
            ."Title: {$issue['title']}\n"
            ."Message: {$issue['message']}\n"
            .'Affected Files: '.implode(', ', $issue['affected_files']);
    }

    /**
     * Mock AI rate limit exception.
     */
    protected function mockAiRateLimitException(string $message = 'Rate limit exceeded'): \Exception
    {
        return new AiRateLimitException($message);
    }

    /**
     * Mock AI server exception.
     */
    protected function mockAiServerException(string $message = 'Server error'): \Exception
    {
        return new AiServerException($message);
    }

    /**
     * Mock AI timeout exception.
     */
    protected function mockAiTimeoutException(string $message = 'Request timeout'): \Exception
    {
        return new AiTimeoutException($message);
    }

    /**
     * Mock AI authentication exception.
     */
    protected function mockAiAuthException(string $message = 'Authentication failed'): \Exception
    {
        return new AiAuthenticationException($message);
    }

    /**
     * Mock AI quota exceeded exception.
     */
    protected function mockAiQuotaException(string $message = 'Quota exceeded'): \Exception
    {
        return new AiQuotaExceededException($message);
    }

    /**
     * Create an AI agent mock that expects a specific prompt.
     */
    protected function expectAiPromptContains(string $expectedText, string $response = 'AI response'): AgentContract
    {
        $agent = Mockery::mock(AgentContract::class);
        $agent->shouldReceive('prompt')
            ->with(Mockery::on(function ($prompt) use ($expectedText) {
                return str_contains($prompt, $expectedText);
            }))
            ->andReturn($agent);
        $agent->shouldReceive('text')
            ->andReturn($response);

        return $agent;
    }
}
