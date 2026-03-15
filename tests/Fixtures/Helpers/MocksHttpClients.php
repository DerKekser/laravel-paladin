<?php

namespace Kekser\LaravelPaladin\Tests\Fixtures\Helpers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;

trait MocksHttpClients
{
    /**
     * Create a mock Guzzle client with predefined responses.
     */
    protected function createMockGuzzleClient(array $responses): Client
    {
        $mock = new MockHandler($responses);
        $handlerStack = HandlerStack::create($mock);

        return new Client(['handler' => $handlerStack]);
    }

    /**
     * Create a successful GitHub PR response.
     */
    protected function mockGitHubPRResponse(int $prNumber = 42): Response
    {
        return new Response(201, [], json_encode([
            'html_url' => "https://github.com/test-owner/test-repo/pull/{$prNumber}",
            'number' => $prNumber,
            'state' => 'open',
            'title' => '[Paladin] Fix: Test Issue',
        ]));
    }

    /**
     * Create a GitHub API error response.
     */
    protected function mockGitHubErrorResponse(int $statusCode = 401, string $message = 'Unauthorized'): Response
    {
        return new Response($statusCode, [], json_encode([
            'message' => $message,
        ]));
    }

    /**
     * Create a successful Azure DevOps PR response.
     */
    protected function mockAzurePRResponse(int $prId = 123): Response
    {
        return new Response(201, [], json_encode([
            'pullRequestId' => $prId,
            'status' => 'active',
            'title' => '[Paladin] Fix: Test Issue',
            'repository' => [
                'name' => 'test-repo',
                'project' => ['name' => 'test-project'],
            ],
            'url' => "https://dev.azure.com/test-org/test-project/_git/test-repo/pullrequest/{$prId}",
        ]));
    }

    /**
     * Create an Azure DevOps API error response.
     */
    protected function mockAzureErrorResponse(int $statusCode = 401, string $message = 'Unauthorized'): Response
    {
        return new Response($statusCode, [], json_encode([
            'message' => $message,
        ]));
    }

    /**
     * Create a network timeout exception.
     */
    protected function mockNetworkTimeout(): RequestException
    {
        return new RequestException(
            'Connection timeout',
            new Request('POST', 'test'),
            new Response(408)
        );
    }

    /**
     * Create a network error exception.
     */
    protected function mockNetworkError(string $message = 'Network error'): RequestException
    {
        return new RequestException(
            $message,
            new Request('POST', 'test')
        );
    }

    /**
     * Create a rate limit response.
     */
    protected function mockRateLimitResponse(): Response
    {
        return new Response(429, [
            'Retry-After' => '60',
        ], json_encode([
            'message' => 'API rate limit exceeded',
        ]));
    }

    /**
     * Create a server error response.
     */
    protected function mockServerErrorResponse(int $statusCode = 500): Response
    {
        return new Response($statusCode, [], json_encode([
            'message' => 'Internal server error',
        ]));
    }

    /**
     * Mock Laravel Http facade responses.
     */
    protected function mockHttpResponses(array $responses): void
    {
        Http::fake($responses);
    }

    /**
     * Assert that an HTTP request was sent.
     */
    protected function assertHttpRequestSent(string $url, string $method = 'POST'): void
    {
        Http::assertSent(function ($request) use ($url, $method) {
            return $request->url() === $url && $request->method() === $method;
        });
    }

    /**
     * Assert that no HTTP requests were sent.
     */
    protected function assertNoHttpRequestsSent(): void
    {
        Http::assertNothingSent();
    }
}
