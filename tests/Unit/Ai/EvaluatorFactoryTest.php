<?php

use Kekser\LaravelPaladin\Ai\EvaluatorFactory;
use Kekser\LaravelPaladin\Ai\LaravelAi\LaravelAiEvaluator;
use Kekser\LaravelPaladin\Ai\Opencode\OpenCodeEvaluator;
use Kekser\LaravelPaladin\Contracts\IssueEvaluator;

test('it creates laravel ai evaluator by default', function () {
    config([
        'paladin.ai.evaluator' => 'laravel-ai',
        'paladin.ai.provider' => 'gemini',
        'paladin.ai.credentials.gemini_api_key' => 'test-key',
    ]);

    $factory = new EvaluatorFactory;
    $evaluator = $factory->create();

    expect($evaluator)->toBeInstanceOf(LaravelAiEvaluator::class);
    expect($evaluator)->toBeInstanceOf(IssueEvaluator::class);
});

test('it creates opencode evaluator', function () {
    config(['paladin.ai.evaluator' => 'opencode']);

    $factory = new EvaluatorFactory;
    $evaluator = $factory->create();

    expect($evaluator)->toBeInstanceOf(OpenCodeEvaluator::class);
    expect($evaluator)->toBeInstanceOf(IssueEvaluator::class);
});

test('it defaults to laravel ai when evaluator not set', function () {
    config([
        'paladin.ai.evaluator' => null,
        'paladin.ai.provider' => 'gemini',
        'paladin.ai.credentials.gemini_api_key' => 'test-key',
    ]);

    $factory = new EvaluatorFactory;
    $evaluator = $factory->create();

    expect($evaluator)->toBeInstanceOf(LaravelAiEvaluator::class);
});

test('it throws exception for unsupported evaluator', function () {
    config(['paladin.ai.evaluator' => 'unsupported-evaluator']);

    $factory = new EvaluatorFactory;
    $factory->create();
})->throws(InvalidArgumentException::class, 'Unsupported AI evaluator: unsupported-evaluator');

test('it is case insensitive', function () {
    config(['paladin.ai.evaluator' => 'OpenCode']);

    $factory = new EvaluatorFactory;
    $evaluator = $factory->create();

    expect($evaluator)->toBeInstanceOf(OpenCodeEvaluator::class);
});

test('it creates laravel ai evaluator case insensitive', function () {
    config([
        'paladin.ai.evaluator' => 'Laravel-AI',
        'paladin.ai.provider' => 'gemini',
        'paladin.ai.credentials.gemini_api_key' => 'test-key',
    ]);

    $factory = new EvaluatorFactory;
    $evaluator = $factory->create();

    expect($evaluator)->toBeInstanceOf(LaravelAiEvaluator::class);
});
