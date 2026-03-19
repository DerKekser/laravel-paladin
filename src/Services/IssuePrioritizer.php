<?php

namespace Kekser\LaravelPaladin\Services;

class IssuePrioritizer
{
    protected array $severityOrder = [
        'critical' => 1,
        'high' => 2,
        'medium' => 3,
        'low' => 4,
    ];

    /**
     * Sort issues by severity (critical first, then high, medium, low).
     *
     * @param  array  $issues  Array of issue arrays with 'severity' key
     * @return array Sorted issues
     */
    public function sortBySeverity(array $issues): array
    {
        usort($issues, function ($a, $b) {
            $aSeverity = $this->severityOrder[$a['severity']] ?? 999;
            $bSeverity = $this->severityOrder[$b['severity']] ?? 999;

            return $aSeverity <=> $bSeverity;
        });

        return $issues;
    }

    /**
     * Limit the number of issues to process.
     *
     * @param  array  $issues  Array of issues
     * @param  int|null  $max  Maximum number of issues (defaults to config)
     * @return array Limited issues array
     */
    public function limitIssues(array $issues, ?int $max = null): array
    {
        $max = $max ?? config('paladin.issues.max_per_run', 5);

        return array_slice($issues, 0, $max);
    }

    /**
     * Prioritize and limit issues in one operation.
     *
     * @param  array  $issues  Array of issues to prioritize
     * @param  int|null  $max  Maximum number of issues to return
     * @return array Prioritized and limited issues
     */
    public function prioritize(array $issues, ?int $max = null): array
    {
        $sorted = $this->sortBySeverity($issues);

        return $this->limitIssues($sorted, $max);
    }

    /**
     * Get the severity rank for a given severity level.
     *
     * @param  string  $severity  Severity level
     * @return int Rank (lower is more critical)
     */
    public function getSeverityRank(string $severity): int
    {
        return $this->severityOrder[$severity] ?? 999;
    }
}
