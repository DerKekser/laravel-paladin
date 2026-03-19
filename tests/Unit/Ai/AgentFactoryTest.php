<?php

use Kekser\LaravelPaladin\Ai\AgentFactory;
use Kekser\LaravelPaladin\Ai\LaravelAi\Agents\IssueAnalyzer;
use Kekser\LaravelPaladin\Ai\LaravelAi\Agents\PromptGenerator;
use Laravel\Ai\Enums\Lab;

it('creates issue analyzer', function () {
    config([
        'paladin.ai.provider' => 'gemini',
        'paladin.ai.credentials.gemini_api_key' => 'test-key',
    ]);

    $factory = new AgentFactory;
    $analyzer = $factory->createIssueAnalyzer();

    expect($analyzer)->toBeInstanceOf(IssueAnalyzer::class);
});

it('creates prompt generator', function () {
    config([
        'paladin.ai.provider' => 'gemini',
        'paladin.ai.credentials.gemini_api_key' => 'test-key',
    ]);

    $factory = new AgentFactory;
    $issue = [
        'type' => 'error',
        'severity' => 'high',
        'title' => 'Test Issue',
        'message' => 'Test message',
    ];

    $generator = $factory->createPromptGenerator($issue);

    expect($generator)->toBeInstanceOf(PromptGenerator::class);
});

it('throws exception when provider not configured', function () {
    config(['paladin.ai.provider' => null]);

    new AgentFactory;
})->throws(RuntimeException::class, 'AI provider not configured');

it('throws exception for unsupported provider', function () {
    config(['paladin.ai.provider' => 'unsupported-provider']);

    new AgentFactory;
})->throws(InvalidArgumentException::class, 'Unsupported AI provider');

it('validates gemini credentials', function () {
    config([
        'paladin.ai.provider' => 'gemini',
        'paladin.ai.credentials.gemini_api_key' => '', // Empty key
    ]);

    new AgentFactory;
})->throws(RuntimeException::class, 'GEMINI_API_KEY');

it('validates multiple missing credentials', function () {
    config([
        'paladin.ai.provider' => 'azure',
        'paladin.ai.credentials.azure_openai_api_key' => '',
        'paladin.ai.credentials.azure_openai_endpoint' => '',
    ]);

    new AgentFactory;
})->throws(RuntimeException::class, 'AZURE_OPENAI_API_KEY, AZURE_OPENAI_ENDPOINT');

it('handles ollama provider without credentials', function () {
    config([
        'paladin.ai.provider' => 'ollama',
    ]);

    $factory = new AgentFactory;
    $reflection = new ReflectionClass($factory);
    $property = $reflection->getProperty('provider');
    $property->setAccessible(true);

    expect($property->getValue($factory))->toBe(Lab::Ollama);
});

it('supports all major providers', function ($providerName, $expectedLab, $credentials = []) {
    config(['paladin.ai.provider' => $providerName]);
    foreach ($credentials as $key => $value) {
        config(["paladin.ai.credentials.$key" => $value]);
    }

    $factory = new AgentFactory;

    // We can't easily access the protected $provider property without reflection
    $reflection = new ReflectionClass($factory);
    $property = $reflection->getProperty('provider');
    $property->setAccessible(true);

    expect($property->getValue($factory))->toBe($expectedLab);
})->with([
    ['anthropic', Lab::Anthropic, ['anthropic_api_key' => 'key']],
    ['openai', Lab::OpenAI, ['openai_api_key' => 'key']],
    ['gemini', Lab::Gemini, ['gemini_api_key' => 'key']],
    ['ollama', Lab::Ollama],
    ['azure', Lab::Azure, ['azure_openai_api_key' => 'key', 'azure_openai_endpoint' => 'endpoint']],
    ['deepseek', Lab::DeepSeek, ['deepseek_api_key' => 'key']],
    ['groq', Lab::Groq, ['groq_api_key' => 'key']],
    ['mistral', Lab::Mistral, ['mistral_api_key' => 'key']],
    ['openrouter', Lab::OpenRouter, ['openrouter_api_key' => 'key']],
    ['xai', Lab::xAI, ['xai_api_key' => 'key']],
    ['cohere', Lab::Cohere, ['cohere_api_key' => 'key']],
]);
