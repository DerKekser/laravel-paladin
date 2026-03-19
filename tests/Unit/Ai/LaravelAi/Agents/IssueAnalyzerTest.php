<?php

namespace Tests\Unit\Ai\LaravelAi\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Kekser\LaravelPaladin\Ai\AiProviderRetryHandler;
use Kekser\LaravelPaladin\Ai\LaravelAi\Agents\IssueAnalyzer;
use Laravel\Ai\Enums\Lab;
use Mockery;

beforeEach(function () {
    $this->analyzer = new IssueAnalyzer;
});

it('returns instructions', function () {
    $instructions = $this->analyzer->instructions();
    expect($instructions)->toBeString()
        ->and($instructions)->toContain('expert Laravel application debugger')
        ->and($instructions)->toContain('critical', 'high', 'medium', 'low');
});

it('sets and gets provider', function () {
    $this->analyzer->setProvider(Lab::Gemini);
    expect($this->analyzer->provider())->toBe(Lab::Gemini);
});

it('defines schema', function () {
    $mockSchema = Mockery::mock(JsonSchema::class);
    $mockArray = Mockery::mock();
    $mockObject = Mockery::mock();
    $mockString = Mockery::mock();
    $mockEnum = Mockery::mock();
    $mockDescription = Mockery::mock();
    $mockRequired = Mockery::mock();

    $mockSchema->shouldReceive('array')->andReturn($mockArray);
    $mockSchema->shouldReceive('object')->andReturn($mockObject);
    $mockSchema->shouldReceive('string')->andReturn($mockString);

    $mockArray->shouldReceive('items')->withAnyArgs()->andReturnSelf();
    $mockArray->shouldReceive('description')->andReturnSelf();
    $mockArray->shouldReceive('required')->andReturn($mockRequired);

    $mockObject->shouldReceive('required')->andReturn($mockRequired);

    $mockString->shouldReceive('description')->andReturnSelf();
    $mockString->shouldReceive('enum')->with(['critical', 'high', 'medium', 'low'])->andReturnSelf();
    $mockString->shouldReceive('required')->andReturn($mockRequired);

    $schema = $this->analyzer->schema($mockSchema);

    expect($schema)->toHaveKey('issues');
});

it('analyzes log entries', function () {
    $logEntries = [
        [
            'timestamp' => 1710500000,
            'level' => 'error',
            'message' => 'Test error message',
            'stack_trace' => '#0 /var/www/app/Http/Controllers/UserController.php(42)',
        ],
    ];

    $expectedIssues = [
        [
            'id' => 'test-id',
            'type' => 'ErrorException',
            'severity' => 'high',
            'title' => 'Test title',
            'message' => 'Test error message',
            'suggested_fix' => 'Test fix',
            'log_level' => 'error',
        ],
    ];

    $this->analyzer->setProvider(Lab::Gemini);

    $mockRetryHandler = Mockery::mock(AiProviderRetryHandler::class);
    $mockRetryHandler->shouldReceive('executeWithRetry')
        ->once()
        ->with(Mockery::on(function ($callable) {
            // Simulate the prompt call within the callable
            // Note: In real test, we would need to mock the prompt trait or the underlying client
            // Since we're unit testing IssueAnalyzer, we just want to ensure it calls executeWithRetry
            // with the right context and returns the 'issues' part of the response.
            return true;
        }), Mockery::on(function ($context) use ($logEntries) {
            return $context['agent'] === 'IssueAnalyzer' &&
                   $context['log_entries_count'] === count($logEntries) &&
                   $context['provider'] === Lab::Gemini->value;
        }))
        ->andReturn(['issues' => $expectedIssues]);

    app()->instance(AiProviderRetryHandler::class, $mockRetryHandler);

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toBe($expectedIssues);
});

it('respects configuration for model and temperature', function () {
    config([
        'paladin.ai.model' => 'test-model',
        'paladin.ai.temperature' => 0.5,
    ]);

    // getModel and temperature are protected, but we can verify them via reflection
    $reflection = new \ReflectionClass(IssueAnalyzer::class);

    $getModelMethod = $reflection->getMethod('getModel');
    $getModelMethod->setAccessible(true);
    expect($getModelMethod->invoke($this->analyzer))->toBe('test-model');

    $temperatureMethod = $reflection->getMethod('temperature');
    $temperatureMethod->setAccessible(true);
    expect($temperatureMethod->invoke($this->analyzer))->toBe(0.5);
});

it('uses default values for model and temperature when config is missing', function () {
    // In these tests, sometimes the config is already partially populated.
    // Instead of unset, let's just make sure we test what happens when config returns the default.
    // We can't easily "unset" if it's already in the loaded config file without more work.
    // Let's just mock the config or set it to something and then test defaults if not set.

    // Actually, let's just use the default test which passed (except for the null issue)
    config(['paladin.ai' => []]);

    $reflection = new \ReflectionClass(IssueAnalyzer::class);

    $getModelMethod = $reflection->getMethod('getModel');
    $getModelMethod->setAccessible(true);
    expect($getModelMethod->invoke($this->analyzer))->toBe('gemini-2.0-flash-exp');

    $temperatureMethod = $reflection->getMethod('temperature');
    $temperatureMethod->setAccessible(true);
    expect($temperatureMethod->invoke($this->analyzer))->toBe(0.7);
});
