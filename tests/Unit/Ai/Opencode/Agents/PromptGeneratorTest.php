<?php

use Kekser\LaravelPaladin\Ai\Opencode\Agents\PromptGenerator;
use Kekser\LaravelPaladin\Services\OpenCodeRunner;

beforeEach(function () {
    $this->runnerMock = Mockery::mock(OpenCodeRunner::class);
    $this->generator = app(PromptGenerator::class);

    // Inject mock via reflection
    $reflection = new ReflectionClass(PromptGenerator::class);
    $property = $reflection->getProperty('runner');
    $property->setAccessible(true);
    $property->setValue($this->generator, $this->runnerMock);
});

test('it generates prompt successfully', function () {
    $issue = [
        'type' => 'Bug',
        'severity' => 'High',
        'title' => 'Test title',
        'message' => 'Test error message',
    ];

    $this->runnerMock->shouldReceive('run')
        ->once()
        ->andReturn([
            'success' => true,
            'stdout' => 'Generated prompt',
            'return_code' => 0,
            'output' => 'Generated prompt',
        ]);

    $result = $this->generator->generate($issue);

    expect($result)->toBe('Generated prompt');
});

test('it handles generation failure', function () {
    $issue = [
        'type' => 'Bug',
        'severity' => 'High',
        'title' => 'Test title',
        'message' => 'Test error message',
    ];

    $this->runnerMock->shouldReceive('run')
        ->once()
        ->andReturn([
            'success' => false,
            'stdout' => '',
            'return_code' => 1,
            'output' => 'Error',
        ]);

    $this->generator->generate($issue);
})->throws(RuntimeException::class, 'OpenCode prompt generation failed: Error');
