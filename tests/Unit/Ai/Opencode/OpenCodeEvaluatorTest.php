<?php

use Kekser\LaravelPaladin\Ai\Opencode\Agents\IssueAnalyzer;
use Kekser\LaravelPaladin\Ai\Opencode\Agents\PromptGenerator;
use Kekser\LaravelPaladin\Ai\Opencode\OpenCodeEvaluator;
use Kekser\LaravelPaladin\Services\OpenCodeRunner;

beforeEach(function () {
    $this->runnerMock = Mockery::mock(OpenCodeRunner::class);
    $this->analyzerMock = Mockery::mock(IssueAnalyzer::class);
    $this->generatorMock = Mockery::mock(PromptGenerator::class);

    $this->evaluator = app(OpenCodeEvaluator::class);

    $reflection = new ReflectionClass(OpenCodeEvaluator::class);

    $runnerProp = $reflection->getProperty('runner');
    $runnerProp->setAccessible(true);
    $runnerProp->setValue($this->evaluator, $this->runnerMock);

    $analyzerProp = $reflection->getProperty('analyzer');
    $analyzerProp->setAccessible(true);
    $analyzerProp->setValue($this->evaluator, $this->analyzerMock);

    $generatorProp = $reflection->getProperty('promptGenerator');
    $generatorProp->setAccessible(true);
    $generatorProp->setValue($this->evaluator, $this->generatorMock);
});

test('it analyzes issues', function () {
    $logEntries = ['entry 1'];
    $expectedResult = [['id' => 1]];

    $this->analyzerMock->shouldReceive('analyze')
        ->once()
        ->with($logEntries)
        ->andReturn($expectedResult);

    expect($this->evaluator->analyzeIssues($logEntries))->toBe($expectedResult);
});

test('it generates prompt', function () {
    $issue = ['id' => 1];
    $expectedPrompt = 'prompt';

    $this->generatorMock->shouldReceive('generate')
        ->once()
        ->with($issue, null)
        ->andReturn($expectedPrompt);

    expect($this->evaluator->generatePrompt($issue))->toBe($expectedPrompt);
});

test('it checks configuration', function () {
    $this->runnerMock->shouldReceive('isAvailable')
        ->once()
        ->andReturn(true);

    expect($this->evaluator->isConfigured())->toBeTrue();
});
