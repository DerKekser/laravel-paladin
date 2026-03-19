<?php

use Kekser\LaravelPaladin\Ai\Opencode\Agents\IssueAnalyzer;
use Kekser\LaravelPaladin\Ai\Opencode\Agents\PromptGenerator;
use Kekser\LaravelPaladin\Ai\Opencode\OpenCodeEvaluator;
use Kekser\LaravelPaladin\Contracts\IssueEvaluator;
use Kekser\LaravelPaladin\Services\OpenCodeRunner;

/**
 * Helper to set a protected property on an object.
 */
function setProtectedProperty(object $object, string $property, mixed $value): void
{
    $reflection = new ReflectionClass($object);
    $prop = $reflection->getProperty($property);
    $prop->setAccessible(true);
    $prop->setValue($object, $value);
}

test('it implements issue evaluator contract', function () {
    $evaluator = app(OpenCodeEvaluator::class);

    expect($evaluator)->toBeInstanceOf(IssueEvaluator::class);
});

test('it analyzes issues successfully', function () {
    $mockAnalyzer = Mockery::mock(IssueAnalyzer::class);
    $mockAnalyzer->shouldReceive('analyze')
        ->once()
        ->andReturn([
            [
                'id' => 'abc123',
                'type' => 'ErrorException',
                'severity' => 'high',
                'title' => 'Division by zero',
                'message' => 'Division by zero in UserController',
                'stack_trace' => '#0 app/Http/Controllers/UserController.php(42)',
                'affected_files' => ['app/Http/Controllers/UserController.php'],
                'suggested_fix' => 'Add zero check',
                'log_level' => 'error',
            ],
        ]);

    $evaluator = app(OpenCodeEvaluator::class);
    setProtectedProperty($evaluator, 'analyzer', $mockAnalyzer);

    $logEntries = [
        [
            'timestamp' => time(),
            'level' => 'error',
            'message' => 'Division by zero',
            'stack_trace' => '#0 app/Http/Controllers/UserController.php(42)',
        ],
    ];

    $issues = $evaluator->analyzeIssues($logEntries);

    expect($issues)->toHaveCount(1);
    expect($issues[0]['id'])->toBe('abc123');
    expect($issues[0]['type'])->toBe('ErrorException');
    expect($issues[0]['severity'])->toBe('high');
});

test('it generates prompt successfully', function () {
    $mockGenerator = Mockery::mock(PromptGenerator::class);
    $mockGenerator->shouldReceive('generate')
        ->once()
        ->andReturn('Fix the division by zero error in UserController by adding a validation check.');

    $evaluator = app(OpenCodeEvaluator::class);
    setProtectedProperty($evaluator, 'promptGenerator', $mockGenerator);

    $issue = [
        'id' => 'abc123',
        'type' => 'ErrorException',
        'severity' => 'high',
        'title' => 'Division by zero',
        'message' => 'Division by zero in UserController',
        'affected_files' => ['app/Http/Controllers/UserController.php'],
        'suggested_fix' => 'Add zero check',
    ];

    $prompt = $evaluator->generatePrompt($issue);

    expect($prompt)->toContain('Fix the division by zero error');
});

test('it reports configured when opencode is available', function () {
    $mockRunner = Mockery::mock(OpenCodeRunner::class);
    $mockRunner->shouldReceive('isAvailable')->andReturn(true);

    $evaluator = app(OpenCodeEvaluator::class);
    setProtectedProperty($evaluator, 'runner', $mockRunner);

    expect($evaluator->isConfigured())->toBeTrue();
    expect($evaluator->getConfigurationErrors())->toBeEmpty();
});

test('it reports not configured when opencode unavailable', function () {
    $mockRunner = Mockery::mock(OpenCodeRunner::class);
    $mockRunner->shouldReceive('isAvailable')->andReturn(false);

    $evaluator = app(OpenCodeEvaluator::class);
    setProtectedProperty($evaluator, 'runner', $mockRunner);

    expect($evaluator->isConfigured())->toBeFalse();

    $errors = $evaluator->getConfigurationErrors();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('OpenCode binary is not available');
});
