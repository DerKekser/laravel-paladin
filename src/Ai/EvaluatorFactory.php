<?php

namespace Kekser\LaravelPaladin\Ai;

use InvalidArgumentException;
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

        $evaluatorName = strtolower(config('paladin.evaluator', 'laravel-ai') ?: 'laravel-ai');

        $config = config("paladin.evaluators.{$evaluatorName}");
        $class = is_array($config) ? ($config['driver'] ?? null) : $config;

        if (! $class || ! class_exists($class)) {
            throw new InvalidArgumentException(
                "Unsupported AI evaluator: {$evaluatorName}. Check your config/paladin.php configuration."
            );
        }

        $this->evaluator = app($class);

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
