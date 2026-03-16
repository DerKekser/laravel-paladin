<?php

namespace Kekser\LaravelPaladin\Tests\Unit\Ai\Evaluators;

use Kekser\LaravelPaladin\Ai\Evaluators\LaravelAiEvaluator;
use Kekser\LaravelPaladin\Contracts\IssueEvaluator;
use Kekser\LaravelPaladin\Tests\TestCase;

class LaravelAiEvaluatorTest extends TestCase
{
    /** @test */
    public function it_implements_issue_evaluator_contract()
    {
        config([
            'paladin.ai.provider' => 'gemini',
            'paladin.ai.credentials.gemini_api_key' => 'test-key',
        ]);

        $evaluator = new LaravelAiEvaluator;

        $this->assertInstanceOf(IssueEvaluator::class, $evaluator);
    }

    /** @test */
    public function it_reports_configured_when_provider_is_valid()
    {
        config([
            'paladin.ai.provider' => 'gemini',
            'paladin.ai.credentials.gemini_api_key' => 'test-key',
        ]);

        $evaluator = new LaravelAiEvaluator;

        $this->assertTrue($evaluator->isConfigured());
        $this->assertEmpty($evaluator->getConfigurationErrors());
    }

    /** @test */
    public function it_reports_not_configured_when_provider_missing()
    {
        config(['paladin.ai.provider' => null]);

        // With lazy-loading, the constructor no longer throws.
        // Instead, getConfigurationErrors() should report the issue.
        $evaluator = new LaravelAiEvaluator;

        $this->assertFalse($evaluator->isConfigured());
        $errors = $evaluator->getConfigurationErrors();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('AI provider not configured', $errors[0]);
    }

    /** @test */
    public function it_reports_not_configured_when_credentials_missing()
    {
        config([
            'paladin.ai.provider' => 'gemini',
            'paladin.ai.credentials.gemini_api_key' => '',
        ]);

        // With lazy-loading, the constructor no longer throws.
        $evaluator = new LaravelAiEvaluator;

        $this->assertFalse($evaluator->isConfigured());
        $errors = $evaluator->getConfigurationErrors();
        $this->assertNotEmpty($errors);
    }

    /** @test */
    public function it_reports_configuration_errors()
    {
        config(['paladin.ai.provider' => null]);

        $evaluator = new LaravelAiEvaluator;

        $errors = $evaluator->getConfigurationErrors();
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('AI provider not configured', $errors[0]);
    }

    /** @test */
    public function it_reports_empty_errors_when_properly_configured()
    {
        config([
            'paladin.ai.provider' => 'gemini',
            'paladin.ai.credentials.gemini_api_key' => 'test-key',
        ]);

        $evaluator = new LaravelAiEvaluator;

        $errors = $evaluator->getConfigurationErrors();
        $this->assertEmpty($errors);
    }
}
