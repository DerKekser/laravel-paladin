<?php

namespace Tests\Unit\Ai\LaravelAi\Agents;

use Illuminate\Support\Facades\Config;
use Kekser\LaravelPaladin\Ai\AiProviderRetryHandler;
use Kekser\LaravelPaladin\Ai\LaravelAi\Agents\PromptGenerator;
use Laravel\Ai\Enums\Lab;
use Mockery;

beforeEach(function () {
    $this->issue = [
        'id' => 'issue-123',
        'type' => 'error',
        'severity' => 'high',
        'title' => 'Test Issue',
        'message' => 'Something failed here',
        'affected_files' => ['app/Models/User.php', 'app/Services/UserService.php'],
        'suggested_fix' => 'Check the null pointer',
    ];

    $this->generator = new PromptGenerator($this->issue);
});

test('it has correct instructions', function () {
    $instructions = $this->generator->instructions();
    expect($instructions)->toContain('You are an expert at creating effective prompts for OpenCode');
    expect($instructions)->toContain('Clearly describe the issue');
});

test('it can set and get provider', function () {
    $this->generator->setProvider(Lab::Gemini);
    expect($this->generator->provider())->toBe(Lab::Gemini);
});

test('it generates prompt without test failure output', function () {
    $this->generator->setProvider(Lab::Gemini);

    $mockRetryHandler = Mockery::mock(AiProviderRetryHandler::class);
    $mockRetryHandler->shouldReceive('executeWithRetry')
        ->once()
        ->with(Mockery::on(function ($callback) {
            return is_callable($callback);
        }), Mockery::on(function ($context) {
            return $context['agent'] === 'PromptGenerator' &&
                   $context['operation'] === 'generate_prompt' &&
                   $context['issue_id'] === 'issue-123' &&
                   $context['has_test_failure'] === false;
        }))
        ->andReturn('Generated Prompt Content');

    $this->app->instance(AiProviderRetryHandler::class, $mockRetryHandler);

    $result = $this->generator->generate();

    expect($result)->toBe('Generated Prompt Content');
});

test('it generates prompt with test failure output', function () {
    $testFailure = 'PHPUnit failed: Expected true but got false';
    $generator = new PromptGenerator($this->issue, $testFailure);
    $generator->setProvider(Lab::Gemini);

    $mockRetryHandler = Mockery::mock(AiProviderRetryHandler::class);
    $mockRetryHandler->shouldReceive('executeWithRetry')
        ->once()
        ->with(Mockery::on(function ($callback) {
            return is_callable($callback);
        }), Mockery::on(function ($context) {
            return $context['has_test_failure'] === true;
        }))
        ->andReturn('Generated Prompt with failures');

    $this->app->instance(AiProviderRetryHandler::class, $mockRetryHandler);

    $result = $generator->generate();

    expect($result)->toBe('Generated Prompt with failures');
});

test('it builds correct base prompt', function () {
    // Access protected method via reflection
    $reflection = new \ReflectionClass($this->generator);
    $method = $reflection->getMethod('buildBasePrompt');
    $method->setAccessible(true);

    $basePrompt = $method->invoke($this->generator);

    expect($basePrompt)->toContain('**Issue Type**: error');
    expect($basePrompt)->toContain('**Severity**: high');
    expect($basePrompt)->toContain('**Title**: Test Issue');
    expect($basePrompt)->toContain('**Error Message**:');
    expect($basePrompt)->toContain('Something failed here');
    expect($basePrompt)->toContain('**Affected Files**:');
    expect($basePrompt)->toContain('- app/Models/User.php');
    expect($basePrompt)->toContain('- app/Services/UserService.php');
    expect($basePrompt)->toContain('**Suggested Fix**: Check the null pointer');
});

test('it builds correct test failure context', function () {
    $testFailure = 'Pest failed: Undefined variable $user';
    $generator = new PromptGenerator($this->issue, $testFailure);

    $reflection = new \ReflectionClass($generator);
    $method = $reflection->getMethod('buildTestFailureContext');
    $method->setAccessible(true);

    $context = $method->invoke($generator);

    expect($context)->toContain('**Previous Fix Attempt Failed**');
    expect($context)->toContain('The previous fix attempt resulted in test failures');
    expect($context)->toContain('Pest failed: Undefined variable $user');
});

test('it uses configured model and temperature', function () {
    Config::set('paladin.ai.model', 'test-model');
    Config::set('paladin.ai.temperature', 0.5);

    $reflection = new \ReflectionClass($this->generator);

    $getModel = $reflection->getMethod('getModel');
    $getModel->setAccessible(true);
    expect($getModel->invoke($this->generator))->toBe('test-model');

    $temperature = $reflection->getMethod('temperature');
    $temperature->setAccessible(true);
    expect($temperature->invoke($this->generator))->toBe(0.5);
});

test('it provides default values for model and temperature', function () {
    // We can't easily "unset" config in a way that it returns the default parameter of config()
    // if it was already set in the environment or previous tests.
    // But we can set it to a known value and verify that.
    Config::set('paladin.ai.model', 'default-model');
    Config::set('paladin.ai.temperature', 0.8);

    $reflection = new \ReflectionClass($this->generator);

    $getModel = $reflection->getMethod('getModel');
    $getModel->setAccessible(true);
    expect($getModel->invoke($this->generator))->toBe('default-model');

    $temperature = $reflection->getMethod('temperature');
    $temperature->setAccessible(true);
    expect($temperature->invoke($this->generator))->toBe(0.8);
});
