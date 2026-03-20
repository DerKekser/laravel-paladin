<?php

use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Ai\Simple\Agents\IssueAnalyzer;
use Kekser\LaravelPaladin\Ai\Simple\Agents\PromptGenerator;
use Kekser\LaravelPaladin\Ai\Simple\SimpleEvaluator;
use Kekser\LaravelPaladin\Contracts\IssueEvaluator;

beforeEach(function () {
    $this->analyzerMock = Mockery::mock(IssueAnalyzer::class);
    $this->generatorMock = Mockery::mock(PromptGenerator::class);

    $this->evaluator = app(SimpleEvaluator::class);

    // Inject mocks via reflection
    $reflection = new ReflectionClass(SimpleEvaluator::class);

    $analyzerProp = $reflection->getProperty('analyzer');
    $analyzerProp->setAccessible(true);
    $analyzerProp->setValue($this->evaluator, $this->analyzerMock);

    $generatorProp = $reflection->getProperty('promptGenerator');
    $generatorProp->setAccessible(true);
    $generatorProp->setValue($this->evaluator, $this->generatorMock);

    Log::spy();
});

test('it implements IssueEvaluator interface', function () {
    expect($this->evaluator)->toBeInstanceOf(IssueEvaluator::class);
});

test('it analyzes issues', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'error',
            'message' => 'Test error',
            'stack_trace' => '',
        ],
    ];
    $expectedResult = [
        [
            'id' => 'test-id',
            'type' => 'ErrorException',
            'severity' => 'high',
            'title' => 'Test title',
            'message' => 'Test error',
            'stack_trace' => '',
            'affected_files' => [],
            'suggested_fix' => '',
            'log_level' => 'error',
        ],
    ];

    $this->analyzerMock->shouldReceive('analyze')
        ->once()
        ->with($logEntries)
        ->andReturn($expectedResult);

    $result = $this->evaluator->analyzeIssues($logEntries);

    expect($result)->toBe($expectedResult);
});

test('it generates prompt', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'ErrorException',
        'severity' => 'high',
        'title' => 'Test title',
        'message' => 'Test message',
    ];
    $expectedPrompt = 'This is a generated prompt to fix the issue.';

    $this->generatorMock->shouldReceive('generate')
        ->once()
        ->with($issue, null)
        ->andReturn($expectedPrompt);

    $result = $this->evaluator->generatePrompt($issue);

    expect($result)->toBe($expectedPrompt);
});

test('it generates prompt with test failure output', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'ErrorException',
        'severity' => 'high',
        'title' => 'Test title',
        'message' => 'Test message',
    ];
    $testFailureOutput = 'Test failed: Expected true, got false';
    $expectedPrompt = 'This is a generated prompt with test failure context.';

    $this->generatorMock->shouldReceive('generate')
        ->once()
        ->with($issue, $testFailureOutput)
        ->andReturn($expectedPrompt);

    $result = $this->evaluator->generatePrompt($issue, $testFailureOutput);

    expect($result)->toBe($expectedPrompt);
});

test('it is always configured', function () {
    expect($this->evaluator->isConfigured())->toBeTrue();
});

test('it returns empty configuration errors', function () {
    $errors = $this->evaluator->getConfigurationErrors();

    expect($errors)->toBe([]);
});

test('it logs when analyzing issues', function () {
    $logEntries = [
        ['message' => 'Test'],
    ];

    $this->analyzerMock->shouldReceive('analyze')
        ->once()
        ->andReturn([]);

    $this->evaluator->analyzeIssues($logEntries);

    Log::shouldHaveReceived('info')
        ->with('[Paladin] Analyzing issues with Simple evaluator (no AI)', Mockery::any());
});

test('it logs when generating prompts', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'ErrorException',
        'severity' => 'high',
        'title' => 'Test title',
        'message' => 'Test message',
    ];

    $this->generatorMock->shouldReceive('generate')
        ->once()
        ->andReturn('prompt');

    $this->evaluator->generatePrompt($issue);

    Log::shouldHaveReceived('info')
        ->with('[Paladin] Generating prompt with Simple evaluator (no AI)', Mockery::any());
});

test('it handles empty log entries', function () {
    $logEntries = [];

    $this->analyzerMock->shouldReceive('analyze')
        ->once()
        ->with($logEntries)
        ->andReturn([]);

    $result = $this->evaluator->analyzeIssues($logEntries);

    expect($result)->toBe([]);
});

test('it handles multiple issues', function () {
    $logEntries = [
        ['message' => 'Error 1'],
        ['message' => 'Error 2'],
    ];
    $expectedIssues = [
        ['id' => '1', 'type' => 'Error1'],
        ['id' => '2', 'type' => 'Error2'],
    ];

    $this->analyzerMock->shouldReceive('analyze')
        ->once()
        ->with($logEntries)
        ->andReturn($expectedIssues);

    $result = $this->evaluator->analyzeIssues($logEntries);

    expect($result)->toHaveCount(2);
    expect($result[0]['type'])->toBe('Error1');
    expect($result[1]['type'])->toBe('Error2');
});
