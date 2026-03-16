<?php

namespace Kekser\LaravelPaladin\Tests\Unit\Ai;

use Kekser\LaravelPaladin\Ai\EvaluatorFactory;
use Kekser\LaravelPaladin\Ai\Evaluators\LaravelAiEvaluator;
use Kekser\LaravelPaladin\Ai\Evaluators\OpenCodeEvaluator;
use Kekser\LaravelPaladin\Contracts\IssueEvaluator;
use Kekser\LaravelPaladin\Tests\TestCase;

class EvaluatorFactoryTest extends TestCase
{
    /** @test */
    public function it_creates_laravel_ai_evaluator_by_default()
    {
        config([
            'paladin.ai.evaluator' => 'laravel-ai',
            'paladin.ai.provider' => 'gemini',
            'paladin.ai.credentials.gemini_api_key' => 'test-key',
        ]);

        $factory = new EvaluatorFactory;
        $evaluator = $factory->create();

        $this->assertInstanceOf(LaravelAiEvaluator::class, $evaluator);
        $this->assertInstanceOf(IssueEvaluator::class, $evaluator);
    }

    /** @test */
    public function it_creates_opencode_evaluator()
    {
        config(['paladin.ai.evaluator' => 'opencode']);

        $factory = new EvaluatorFactory;
        $evaluator = $factory->create();

        $this->assertInstanceOf(OpenCodeEvaluator::class, $evaluator);
        $this->assertInstanceOf(IssueEvaluator::class, $evaluator);
    }

    /** @test */
    public function it_defaults_to_laravel_ai_when_evaluator_not_set()
    {
        config([
            'paladin.ai.evaluator' => null,
            'paladin.ai.provider' => 'gemini',
            'paladin.ai.credentials.gemini_api_key' => 'test-key',
        ]);

        $factory = new EvaluatorFactory;
        $evaluator = $factory->create();

        $this->assertInstanceOf(LaravelAiEvaluator::class, $evaluator);
    }

    /** @test */
    public function it_throws_exception_for_unsupported_evaluator()
    {
        config(['paladin.ai.evaluator' => 'unsupported-evaluator']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported AI evaluator: unsupported-evaluator');

        $factory = new EvaluatorFactory;
        $factory->create();
    }

    /** @test */
    public function it_is_case_insensitive()
    {
        config(['paladin.ai.evaluator' => 'OpenCode']);

        $factory = new EvaluatorFactory;
        $evaluator = $factory->create();

        $this->assertInstanceOf(OpenCodeEvaluator::class, $evaluator);
    }

    /** @test */
    public function it_creates_laravel_ai_evaluator_case_insensitive()
    {
        config([
            'paladin.ai.evaluator' => 'Laravel-AI',
            'paladin.ai.provider' => 'gemini',
            'paladin.ai.credentials.gemini_api_key' => 'test-key',
        ]);

        $factory = new EvaluatorFactory;
        $evaluator = $factory->create();

        $this->assertInstanceOf(LaravelAiEvaluator::class, $evaluator);
    }
}
