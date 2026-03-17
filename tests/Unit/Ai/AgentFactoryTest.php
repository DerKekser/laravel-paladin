<?php

use Kekser\LaravelPaladin\Ai\AgentFactory;
use Kekser\LaravelPaladin\Ai\LaravelAi\Agents\IssueAnalyzer;
use Kekser\LaravelPaladin\Ai\LaravelAi\Agents\PromptGenerator;

test('it creates issue analyzer', function () {
    config([
        'paladin.ai.provider' => 'gemini',
        'paladin.ai.credentials.gemini_api_key' => 'test-key',
    ]);

    $factory = new AgentFactory;
    $analyzer = $factory->createIssueAnalyzer();

    expect($analyzer)->toBeInstanceOf(IssueAnalyzer::class);
});

test('it creates prompt generator', function () {
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

test('it throws exception when provider not configured', function () {
    config(['paladin.ai.provider' => null]);

    new AgentFactory;
})->throws(RuntimeException::class, 'AI provider not configured');

test('it throws exception for unsupported provider', function () {
    config(['paladin.ai.provider' => 'unsupported-provider']);

    new AgentFactory;
})->throws(InvalidArgumentException::class, 'Unsupported AI provider');

test('it validates gemini credentials', function () {
    config([
        'paladin.ai.provider' => 'gemini',
        'paladin.ai.credentials.gemini_api_key' => '', // Empty key
    ]);

    new AgentFactory;
})->throws(RuntimeException::class, 'GEMINI_API_KEY');

test('it supports all major providers', function () {
    $providers = [
        'gemini' => 'gemini_api_key',
        'openai' => 'openai_api_key',
        'anthropic' => 'anthropic_api_key',
    ];

    foreach ($providers as $provider => $configKey) {
        config([
            'paladin.ai.provider' => $provider,
            "paladin.ai.credentials.{$configKey}" => 'test-key-value',
        ]);

        $factory = new AgentFactory;
        expect($factory)->toBeInstanceOf(AgentFactory::class);
    }
});
