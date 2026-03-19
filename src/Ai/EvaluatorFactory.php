<?php

namespace Kekser\LaravelPaladin\Ai;

use InvalidArgumentException;
use Kekser\LaravelPaladin\Ai\LaravelAi\LaravelAiEvaluator;
use Kekser\LaravelPaladin\Ai\Opencode\OpenCodeEvaluator;
use Kekser\LaravelPaladin\Contracts\IssueEvaluator;

class EvaluatorFactory
{
    /**
     * The active evaluator instance.
     */
    protected ?IssueEvaluator $evaluator = null;

    /**
     * Create or return the configured IssueEvaluator instance.
     */
    public function create(): IssueEvaluator
    {
        if ($this->evaluator !== null) {
            return $this->evaluator;
        }

        $evaluatorName = config('paladin.ai.evaluator', 'laravel-ai') ?: 'laravel-ai';

        $this->evaluator = match (strtolower($evaluatorName)) {
            'laravel-ai' => app(LaravelAiEvaluator::class),
            'opencode' => app(OpenCodeEvaluator::class),
            default => throw new InvalidArgumentException(
                "Unsupported AI evaluator: {$evaluatorName}. Supported evaluators: laravel-ai, opencode"
            ),
        };

        return $this->evaluator;
    }

    /**
     * Explicitly set the evaluator instance.
     */
    public function setEvaluator(IssueEvaluator $evaluator): self
    {
        $this->evaluator = $evaluator;

        return $this;
    }
}
