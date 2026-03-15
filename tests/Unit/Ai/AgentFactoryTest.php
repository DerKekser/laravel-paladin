<?php

namespace Kekser\LaravelPaladin\Tests\Unit\Ai;

use Kekser\LaravelPaladin\Ai\AgentFactory;
use Kekser\LaravelPaladin\Ai\Agents\IssueAnalyzer;
use Kekser\LaravelPaladin\Ai\Agents\PromptGenerator;
use Kekser\LaravelPaladin\Tests\TestCase;

class AgentFactoryTest extends TestCase
{
    /** @test */
    public function it_creates_issue_analyzer()
    {
        config([
            'paladin.ai.provider' => 'gemini',
            'paladin.ai.credentials.gemini_api_key' => 'test-key',
        ]);

        $factory = new AgentFactory;
        $analyzer = $factory->createIssueAnalyzer();

        $this->assertInstanceOf(IssueAnalyzer::class, $analyzer);
    }

    /** @test */
    public function it_creates_prompt_generator()
    {
        config([
            'paladin.ai.provider' => 'gemini',
            'paladin.ai.credentials.gemini_api_key' => 'test-key',
        ]);

        $factory = new AgentFactory;
        $issue = [
            'type' => 'error',
            'severity' => 'high',
            'title' => 'Test Issue',
            'message' => 'Test message',
        ];

        $generator = $factory->createPromptGenerator($issue);

        $this->assertInstanceOf(PromptGenerator::class, $generator);
    }

    /** @test */
    public function it_throws_exception_when_provider_not_configured()
    {
        config(['paladin.ai.provider' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AI provider not configured');

        new AgentFactory;
    }

    /** @test */
    public function it_throws_exception_for_unsupported_provider()
    {
        config(['paladin.ai.provider' => 'unsupported-provider']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported AI provider');

        new AgentFactory;
    }

    /** @test */
    public function it_validates_gemini_credentials()
    {
        config([
            'paladin.ai.provider' => 'gemini',
            'paladin.ai.credentials.gemini_api_key' => '', // Empty key
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/GEMINI_API_KEY/');

        new AgentFactory;
    }

    /** @test */
    public function it_supports_all_major_providers()
    {
        $providers = [
            'gemini' => 'gemini_api_key',
            'openai' => 'openai_api_key',
            'anthropic' => 'anthropic_api_key',
        ];

        foreach ($providers as $provider => $configKey) {
            config([
                'paladin.ai.provider' => $provider,
                "paladin.ai.credentials.{$configKey}" => 'test-key-value',
            ]);

            $factory = new AgentFactory;
            $this->assertInstanceOf(AgentFactory::class, $factory);
        }
    }
}
