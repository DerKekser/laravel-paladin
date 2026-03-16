<?php

namespace Kekser\LaravelPaladin\Tests\Unit\Ai\Evaluators;

use Kekser\LaravelPaladin\Ai\Evaluators\OpenCodeEvaluator;
use Kekser\LaravelPaladin\Contracts\IssueEvaluator;
use Kekser\LaravelPaladin\Services\OpenCodeRunner;
use Kekser\LaravelPaladin\Tests\TestCase;
use Mockery;
use RuntimeException;

class OpenCodeEvaluatorTest extends TestCase
{
    /** @test */
    public function it_implements_issue_evaluator_contract()
    {
        $evaluator = new OpenCodeEvaluator;

        $this->assertInstanceOf(IssueEvaluator::class, $evaluator);
    }

    /** @test */
    public function it_analyzes_issues_with_valid_json_response()
    {
        $mockRunner = Mockery::mock(OpenCodeRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn([
                'success' => true,
                'return_code' => 0,
                'output' => '',
                'stdout' => json_encode([
                    'issues' => [
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
                    ],
                ]),
                'stderr' => '',
            ]);

        $evaluator = new OpenCodeEvaluator;
        $this->setProtectedProperty($evaluator, 'runner', $mockRunner);

        $logEntries = [
            [
                'timestamp' => time(),
                'level' => 'error',
                'message' => 'Division by zero',
                'stack_trace' => '#0 app/Http/Controllers/UserController.php(42)',
            ],
        ];

        $issues = $evaluator->analyzeIssues($logEntries);

        $this->assertCount(1, $issues);
        $this->assertEquals('abc123', $issues[0]['id']);
        $this->assertEquals('ErrorException', $issues[0]['type']);
        $this->assertEquals('high', $issues[0]['severity']);
    }

    /** @test */
    public function it_handles_json_within_code_fences()
    {
        $jsonContent = json_encode([
            'issues' => [
                [
                    'id' => 'def456',
                    'type' => 'QueryException',
                    'severity' => 'critical',
                    'title' => 'Database error',
                    'message' => 'Connection refused',
                    'stack_trace' => '',
                    'affected_files' => [],
                    'suggested_fix' => 'Check database connection',
                    'log_level' => 'critical',
                ],
            ],
        ]);

        $mockRunner = Mockery::mock(OpenCodeRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn([
                'success' => true,
                'return_code' => 0,
                'output' => '',
                'stdout' => "Here is the analysis:\n```json\n{$jsonContent}\n```",
                'stderr' => '',
            ]);

        $evaluator = new OpenCodeEvaluator;
        $this->setProtectedProperty($evaluator, 'runner', $mockRunner);

        $issues = $evaluator->analyzeIssues([
            ['timestamp' => time(), 'level' => 'critical', 'message' => 'Connection refused'],
        ]);

        $this->assertCount(1, $issues);
        $this->assertEquals('def456', $issues[0]['id']);
    }

    /** @test */
    public function it_throws_exception_on_failed_analysis()
    {
        $mockRunner = Mockery::mock(OpenCodeRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn([
                'success' => false,
                'return_code' => 1,
                'output' => 'Some error occurred',
                'stdout' => '',
                'stderr' => 'Some error occurred',
            ]);

        $evaluator = new OpenCodeEvaluator;
        $this->setProtectedProperty($evaluator, 'runner', $mockRunner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenCode issue analysis failed');

        $evaluator->analyzeIssues([
            ['timestamp' => time(), 'level' => 'error', 'message' => 'Test error'],
        ]);
    }

    /** @test */
    public function it_throws_exception_on_invalid_json_output()
    {
        $mockRunner = Mockery::mock(OpenCodeRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn([
                'success' => true,
                'return_code' => 0,
                'output' => '',
                'stdout' => 'This is not valid JSON at all',
                'stderr' => '',
            ]);

        $evaluator = new OpenCodeEvaluator;
        $this->setProtectedProperty($evaluator, 'runner', $mockRunner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse OpenCode analysis output as valid JSON');

        $evaluator->analyzeIssues([
            ['timestamp' => time(), 'level' => 'error', 'message' => 'Test error'],
        ]);
    }

    /** @test */
    public function it_generates_prompt_successfully()
    {
        $mockRunner = Mockery::mock(OpenCodeRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->withArgs(function ($prompt, $dir) {
                return str_contains($prompt, 'Division by zero')
                    && str_contains($prompt, 'ErrorException');
            })
            ->andReturn([
                'success' => true,
                'return_code' => 0,
                'output' => '',
                'stdout' => 'Fix the division by zero error in UserController by adding a validation check.',
                'stderr' => '',
            ]);

        $evaluator = new OpenCodeEvaluator;
        $this->setProtectedProperty($evaluator, 'runner', $mockRunner);

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

        $this->assertStringContainsString('Fix the division by zero error', $prompt);
    }

    /** @test */
    public function it_generates_prompt_with_test_failure_output()
    {
        $mockRunner = Mockery::mock(OpenCodeRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->withArgs(function ($prompt, $dir) {
                return str_contains($prompt, 'Previous Fix Attempt Failed')
                    && str_contains($prompt, 'FAILED Tests\\UserTest');
            })
            ->andReturn([
                'success' => true,
                'return_code' => 0,
                'output' => '',
                'stdout' => 'Fix the error and ensure tests pass.',
                'stderr' => '',
            ]);

        $evaluator = new OpenCodeEvaluator;
        $this->setProtectedProperty($evaluator, 'runner', $mockRunner);

        $issue = [
            'id' => 'abc123',
            'type' => 'ErrorException',
            'severity' => 'high',
            'title' => 'Division by zero',
            'message' => 'Division by zero in UserController',
            'affected_files' => [],
        ];

        $prompt = $evaluator->generatePrompt($issue, 'FAILED Tests\\UserTest::testDivision');

        $this->assertEquals('Fix the error and ensure tests pass.', $prompt);
    }

    /** @test */
    public function it_throws_exception_on_failed_prompt_generation()
    {
        $mockRunner = Mockery::mock(OpenCodeRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn([
                'success' => false,
                'return_code' => 1,
                'output' => 'Error',
                'stdout' => '',
                'stderr' => 'Error',
            ]);

        $evaluator = new OpenCodeEvaluator;
        $this->setProtectedProperty($evaluator, 'runner', $mockRunner);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OpenCode prompt generation failed');

        $evaluator->generatePrompt([
            'id' => 'test',
            'type' => 'error',
            'severity' => 'high',
            'title' => 'Test',
            'message' => 'Test',
            'affected_files' => [],
        ]);
    }

    /** @test */
    public function it_reports_configured_when_opencode_is_available()
    {
        $mockRunner = Mockery::mock(OpenCodeRunner::class);
        $mockRunner->shouldReceive('isAvailable')->andReturn(true);

        $evaluator = new OpenCodeEvaluator;
        $this->setProtectedProperty($evaluator, 'runner', $mockRunner);

        $this->assertTrue($evaluator->isConfigured());
        $this->assertEmpty($evaluator->getConfigurationErrors());
    }

    /** @test */
    public function it_reports_not_configured_when_opencode_unavailable()
    {
        $mockRunner = Mockery::mock(OpenCodeRunner::class);
        $mockRunner->shouldReceive('isAvailable')->andReturn(false);

        $evaluator = new OpenCodeEvaluator;
        $this->setProtectedProperty($evaluator, 'runner', $mockRunner);

        $this->assertFalse($evaluator->isConfigured());

        $errors = $evaluator->getConfigurationErrors();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('OpenCode binary is not available', $errors[0]);
    }

    /** @test */
    public function it_returns_empty_issues_when_no_issues_found()
    {
        $mockRunner = Mockery::mock(OpenCodeRunner::class);
        $mockRunner->shouldReceive('run')
            ->once()
            ->andReturn([
                'success' => true,
                'return_code' => 0,
                'output' => '',
                'stdout' => json_encode(['issues' => []]),
                'stderr' => '',
            ]);

        $evaluator = new OpenCodeEvaluator;
        $this->setProtectedProperty($evaluator, 'runner', $mockRunner);

        $issues = $evaluator->analyzeIssues([
            ['timestamp' => time(), 'level' => 'error', 'message' => 'Test'],
        ]);

        $this->assertEmpty($issues);
    }

    /**
     * Helper to set a protected property on an object.
     */
    protected function setProtectedProperty(object $object, string $property, mixed $value): void
    {
        $reflection = new \ReflectionClass($object);
        $prop = $reflection->getProperty($property);
        $prop->setAccessible(true);
        $prop->setValue($object, $value);
    }
}
