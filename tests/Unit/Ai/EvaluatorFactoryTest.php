<?php

use Kekser\LaravelPaladin\Ai\EvaluatorFactory;
use Kekser\LaravelPaladin\Ai\LaravelAi\LaravelAiEvaluator;
use Kekser\LaravelPaladin\Ai\Opencode\OpenCodeEvaluator;
use Kekser\LaravelPaladin\Contracts\IssueEvaluator;

test('it creates laravel ai evaluator by default', function () {
    config([
        'paladin.evaluator' => 'laravel-ai',
        'paladin.evaluators.laravel-ai.provider' => 'gemini',
        'paladin.evaluators.laravel-ai.credentials.gemini_api_key' => 'test-key',
    ]);

    $factory = app(EvaluatorFactory::class);
    $evaluator = $factory->create();

    expect($evaluator)->toBeInstanceOf(LaravelAiEvaluator::class);
    expect($evaluator)->toBeInstanceOf(IssueEvaluator::class);
});

test('it creates opencode evaluator', function () {
    config(['paladin.evaluator' => 'opencode']);

    $factory = app(EvaluatorFactory::class);
    $evaluator = $factory->create();

    expect($evaluator)->toBeInstanceOf(OpenCodeEvaluator::class);
    expect($evaluator)->toBeInstanceOf(IssueEvaluator::class);
});

test('it defaults to laravel ai when evaluator not set', function () {
    config([
        'paladin.evaluator' => null,
        'paladin.evaluators.laravel-ai.provider' => 'gemini',
        'paladin.evaluators.laravel-ai.credentials.gemini_api_key' => 'test-key',
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
    config(['paladin.evaluator' => 'unsupported-evaluator']);

    $factory = app(EvaluatorFactory::class);
    $factory->create();
})->throws(InvalidArgumentException::class, 'Unsupported AI evaluator: unsupported-evaluator');

test('it is case insensitive', function () {
    config(['paladin.evaluator' => 'OpenCode']);

    $factory = app(EvaluatorFactory::class);
    $evaluator = $factory->create();

    expect($evaluator)->toBeInstanceOf(OpenCodeEvaluator::class);
});

test('it creates laravel ai evaluator case insensitive', function () {
    config([
        'paladin.evaluator' => 'Laravel-AI',
        'paladin.evaluators.laravel-ai.provider' => 'gemini',
        'paladin.evaluators.laravel-ai.credentials.gemini_api_key' => 'test-key',
    ]);

    $factory = app(EvaluatorFactory::class);
    $evaluator = $factory->create();

    expect($evaluator)->toBeInstanceOf(LaravelAiEvaluator::class);
});

test('it can use a custom evaluator via configuration', function () {
    $customEvaluator = new class implements IssueEvaluator
    {
        public function analyzeIssues(array $logEntries): array
        {
            return [];
        }

        public function generatePrompt(array $issue, ?string $testFailureOutput = null): string
        {
            return '';
        }

        public function isConfigured(): bool
        {
            return true;
        }

        public function getConfigurationErrors(): array
        {
            return [];
        }
    };

    config([
        'paladin.evaluator' => 'custom',
        'paladin.evaluators.custom.driver' => get_class($customEvaluator),
    ]);

    app()->instance(get_class($customEvaluator), $customEvaluator);

    $factory = app(EvaluatorFactory::class);
    $result = $factory->create();

    expect($result)->toBe($customEvaluator);
});
