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

    $factory = app(EvaluatorFactory::class);
    $evaluator = $factory->create();

    expect($evaluator)->toBeInstanceOf(LaravelAiEvaluator::class);
    expect($evaluator)->toBeInstanceOf(IssueEvaluator::class);
});

test('it creates opencode evaluator', function () {
    config(['paladin.ai.evaluator' => 'opencode']);

    $factory = app(EvaluatorFactory::class);
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

    $factory = app(EvaluatorFactory::class);
    $evaluator = $factory->create();

    expect($evaluator)->toBeInstanceOf(LaravelAiEvaluator::class);
});

test('it returns existing evaluator instance', function () {
    $factory = app(EvaluatorFactory::class);
    $evaluator1 = $factory->create();
    $evaluator2 = $factory->create();

    expect($evaluator1)->toBe($evaluator2);
});

test('it can explicitly set the evaluator', function () {
    $mockEvaluator = Mockery::mock(IssueEvaluator::class);
    $factory = app(EvaluatorFactory::class);

    $result = $factory->setEvaluator($mockEvaluator);

    expect($result)->toBe($factory);
    expect($factory->create())->toBe($mockEvaluator);
});

test('it throws exception for unsupported evaluator', function () {
    config(['paladin.ai.evaluator' => 'unsupported-evaluator']);

    $factory = app(EvaluatorFactory::class);
    $factory->create();
})->throws(InvalidArgumentException::class, 'Unsupported AI evaluator: unsupported-evaluator');

test('it is case insensitive', function () {
    config(['paladin.ai.evaluator' => 'OpenCode']);

    $factory = app(EvaluatorFactory::class);
    $evaluator = $factory->create();

    expect($evaluator)->toBeInstanceOf(OpenCodeEvaluator::class);
});

test('it creates laravel ai evaluator case insensitive', function () {
    config([
        'paladin.ai.evaluator' => 'Laravel-AI',
        'paladin.ai.provider' => 'gemini',
        'paladin.ai.credentials.gemini_api_key' => 'test-key',
    ]);

    $factory = app(EvaluatorFactory::class);
    $evaluator = $factory->create();

    expect($evaluator)->toBeInstanceOf(LaravelAiEvaluator::class);
});
