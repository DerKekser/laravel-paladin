<?php

use Kekser\LaravelPaladin\Ai\LaravelAi\LaravelAiEvaluator;
use Kekser\LaravelPaladin\Contracts\IssueEvaluator;

test('it implements issue evaluator contract', function () {
    config([
        'paladin.ai.provider' => 'gemini',
        'paladin.ai.credentials.gemini_api_key' => 'test-key',
    ]);

    $evaluator = new LaravelAiEvaluator;

    expect($evaluator)->toBeInstanceOf(IssueEvaluator::class);
});

test('it reports configured when provider is valid', function () {
    config([
        'paladin.ai.provider' => 'gemini',
        'paladin.ai.credentials.gemini_api_key' => 'test-key',
    ]);

    $evaluator = new LaravelAiEvaluator;

    expect($evaluator->isConfigured())->toBeTrue();
    expect($evaluator->getConfigurationErrors())->toBeEmpty();
});

test('it reports not configured when provider missing', function () {
    config(['paladin.ai.provider' => null]);

    // With lazy-loading, the constructor no longer throws.
    // Instead, getConfigurationErrors() should report the issue.
    $evaluator = new LaravelAiEvaluator;

    expect($evaluator->isConfigured())->toBeFalse();
    $errors = $evaluator->getConfigurationErrors();
    expect($errors)->not->toBeEmpty();
    expect($errors[0])->toContain('AI provider not configured');
});

test('it reports not configured when credentials missing', function () {
    config([
        'paladin.ai.provider' => 'gemini',
        'paladin.ai.credentials.gemini_api_key' => '',
    ]);

    // With lazy-loading, the constructor no longer throws.
    $evaluator = new LaravelAiEvaluator;

    expect($evaluator->isConfigured())->toBeFalse();
    $errors = $evaluator->getConfigurationErrors();
    expect($errors)->not->toBeEmpty();
});

test('it reports configuration errors', function () {
    config(['paladin.ai.provider' => null]);

    $evaluator = new LaravelAiEvaluator;

    $errors = $evaluator->getConfigurationErrors();
    expect($errors)->not->toBeEmpty();
    expect($errors[0])->toContain('AI provider not configured');
});

test('it reports empty errors when properly configured', function () {
    config([
        'paladin.ai.provider' => 'gemini',
        'paladin.ai.credentials.gemini_api_key' => 'test-key',
    ]);

    $evaluator = new LaravelAiEvaluator;

    $errors = $evaluator->getConfigurationErrors();
    expect($errors)->toBeEmpty();
});
