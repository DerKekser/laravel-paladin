<?php

namespace Kekser\LaravelPaladin\Ai;

use InvalidArgumentException;
use Kekser\LaravelPaladin\Ai\Evaluators\LaravelAiEvaluator;
use Kekser\LaravelPaladin\Ai\Evaluators\OpenCodeEvaluator;
use Kekser\LaravelPaladin\Contracts\IssueEvaluator;

class EvaluatorFactory
{
    /**
     * Create the configured IssueEvaluator instance.
     */
    public function create(): IssueEvaluator
    {
        $evaluator = config('paladin.ai.evaluator', 'laravel-ai') ?: 'laravel-ai';

        return match (strtolower($evaluator)) {
            'laravel-ai' => new LaravelAiEvaluator,
            'opencode' => new OpenCodeEvaluator,
            default => throw new InvalidArgumentException(
                "Unsupported AI evaluator: {$evaluator}. Supported evaluators: laravel-ai, opencode"
            ),
        };
    }
}
