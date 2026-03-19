<?php

use Kekser\LaravelPaladin\Ai\AgentFactory;
use Kekser\LaravelPaladin\Ai\LaravelAi\Agents\IssueAnalyzer;
use Kekser\LaravelPaladin\Ai\LaravelAi\Agents\PromptGenerator;
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

test('it analyzes issues using issue analyzer agent', function () {
    $logEntries = [['message' => 'test error']];
    $expectedIssues = [['id' => 'issue-1', 'title' => 'Test Issue']];

    $mockAnalyzer = Mockery::mock(IssueAnalyzer::class);
    $mockAnalyzer->shouldReceive('analyze')->with($logEntries)->once()->andReturn($expectedIssues);

    $mockFactory = Mockery::mock(AgentFactory::class);
    $mockFactory->shouldReceive('createIssueAnalyzer')->once()->andReturn($mockAnalyzer);

    app()->instance(AgentFactory::class, $mockFactory);

    $evaluator = new LaravelAiEvaluator;
    $result = $evaluator->analyzeIssues($logEntries);

    expect($result)->toBe($expectedIssues);
});

test('it generates prompt using prompt generator agent', function () {
    $issue = ['id' => 'issue-1', 'title' => 'Test Issue'];
    $testFailureOutput = 'PHPUnit failed...';
    $expectedPrompt = 'Please fix this issue: ...';

    $mockGenerator = Mockery::mock(PromptGenerator::class);
    $mockGenerator->shouldReceive('generate')->once()->andReturn($expectedPrompt);

    $mockFactory = Mockery::mock(AgentFactory::class);
    $mockFactory->shouldReceive('createPromptGenerator')
        ->with($issue, $testFailureOutput)
        ->once()
        ->andReturn($mockGenerator);

    app()->instance(AgentFactory::class, $mockFactory);

    $evaluator = new LaravelAiEvaluator;
    $result = $evaluator->generatePrompt($issue, $testFailureOutput);

    expect($result)->toBe($expectedPrompt);
});
