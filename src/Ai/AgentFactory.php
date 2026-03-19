<?php

namespace Kekser\LaravelPaladin\Ai;

use Kekser\LaravelPaladin\Ai\LaravelAi\Agents\IssueAnalyzer;
use Kekser\LaravelPaladin\Ai\LaravelAi\Agents\PromptGenerator;
use Laravel\Ai\Enums\Lab;

class AgentFactory
{
    protected Lab $provider;

    public function __construct()
    {
        $this->provider = $this->validateAndGetProvider();
    }

    /**
     * Create an IssueAnalyzer instance with the configured provider.
     */
    public function createIssueAnalyzer(): IssueAnalyzer
    {
        $analyzer = app(IssueAnalyzer::class);
        $analyzer->setProvider($this->provider);

        return $analyzer;
    }

    /**
     * Create a PromptGenerator instance with the configured provider.
     */
    public function createPromptGenerator(array $issue, ?string $testFailureOutput = null): PromptGenerator
    {
        $generator = new PromptGenerator($issue, $testFailureOutput);
        $generator->setProvider($this->provider);

        return $generator;
    }

    /**
     * Validate provider configuration and return the Lab enum.
     */
    protected function validateAndGetProvider(): Lab
    {
        $providerName = config('paladin.evaluators.laravel-ai.provider');

        if (! $providerName) {
            throw new \RuntimeException(
                'AI provider not configured. Set PALADIN_AI_PROVIDER in your .env file.'
            );
        }

        // Get the Lab enum for this provider
        $provider = $this->getProviderEnum($providerName);

        // Validate required credentials are set
        $this->validateCredentials($providerName);

        return $provider;
    }

    /**
     * Map provider string to Laravel AI Lab enum.
     */
    protected function getProviderEnum(string $provider): Lab
    {
        return match (strtolower($provider)) {
            'anthropic' => Lab::Anthropic,
            'azure' => Lab::Azure,
            'cohere' => Lab::Cohere,
            'deepseek' => Lab::DeepSeek,
            'gemini' => Lab::Gemini,
            'groq' => Lab::Groq,
            'mistral' => Lab::Mistral,
            'ollama' => Lab::Ollama,
            'openai' => Lab::OpenAI,
            'openrouter' => Lab::OpenRouter,
            'xai' => Lab::xAI,
            default => throw new \InvalidArgumentException(
                "Unsupported AI provider: {$provider}. ".
                'Supported providers: anthropic, azure, cohere, deepseek, gemini, groq, mistral, ollama, openai, openrouter, xai'
            ),
        };
    }

    /**
     * Validate that required environment variables are set for the provider.
     */
    protected function validateCredentials(string $provider): void
    {
        $requirements = [
            'anthropic' => ['anthropic_api_key'],
            'azure' => ['azure_openai_api_key', 'azure_openai_endpoint'],
            'cohere' => ['cohere_api_key'],
            'deepseek' => ['deepseek_api_key'],
            'gemini' => ['gemini_api_key'],
            'groq' => ['groq_api_key'],
            'mistral' => ['mistral_api_key'],
            'ollama' => [], // Optional: ollama_base_url
            'openai' => ['openai_api_key'],
            'openrouter' => ['openrouter_api_key'],
            'xai' => ['xai_api_key'],
        ];

        $providerLower = strtolower($provider);

        if (! isset($requirements[$providerLower])) {
            // This shouldn't happen if getProviderEnum is called first, but just in case
            throw new \InvalidArgumentException(
                "Unknown provider: {$provider}"
            );
        }

        $missing = [];

        foreach ($requirements[$providerLower] as $configKey) {
            $value = config("paladin.evaluators.laravel-ai.credentials.{$configKey}");
            if (empty($value)) {
                // Convert config key to env var name for error message
                $envVar = strtoupper($configKey);
                $missing[] = $envVar;
            }
        }

        if (! empty($missing)) {
            $vars = implode(', ', $missing);
            throw new \RuntimeException(
                "Provider '{$provider}' requires the following environment variable".
                (count($missing) > 1 ? 's' : '')." to be set in your .env file: {$vars}"
            );
        }
    }
}
