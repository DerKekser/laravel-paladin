<?php

use Kekser\LaravelPaladin\Services\IssuePrioritizer;

beforeEach(function () {
    $this->prioritizer = new IssuePrioritizer;
});

test('it sorts issues by severity with critical first', function () {
    $issues = [
        ['id' => '1', 'severity' => 'low'],
        ['id' => '2', 'severity' => 'critical'],
        ['id' => '3', 'severity' => 'medium'],
        ['id' => '4', 'severity' => 'high'],
    ];

    $sorted = $this->prioritizer->sortBySeverity($issues);

    expect($sorted[0]['severity'])->toBe('critical');
    expect($sorted[1]['severity'])->toBe('high');
    expect($sorted[2]['severity'])->toBe('medium');
    expect($sorted[3]['severity'])->toBe('low');
});

test('it handles unknown severity levels by placing them last', function () {
    $issues = [
        ['id' => '1', 'severity' => 'unknown'],
        ['id' => '2', 'severity' => 'high'],
        ['id' => '3', 'severity' => 'critical'],
    ];

    $sorted = $this->prioritizer->sortBySeverity($issues);

    expect($sorted[0]['severity'])->toBe('critical');
    expect($sorted[1]['severity'])->toBe('high');
    expect($sorted[2]['severity'])->toBe('unknown');
});

test('it limits the number of issues', function () {
    $issues = [
        ['id' => '1', 'severity' => 'critical'],
        ['id' => '2', 'severity' => 'high'],
        ['id' => '3', 'severity' => 'medium'],
        ['id' => '4', 'severity' => 'low'],
    ];

    $limited = $this->prioritizer->limitIssues($issues, 2);

    expect($limited)->toHaveCount(2);
    expect($limited[0]['id'])->toBe('1');
    expect($limited[1]['id'])->toBe('2');
});

test('it uses config value for max issues when not specified', function () {
    config(['paladin.issues.max_per_run' => 3]);

    $issues = [
        ['id' => '1'],
        ['id' => '2'],
        ['id' => '3'],
        ['id' => '4'],
        ['id' => '5'],
    ];

    $limited = $this->prioritizer->limitIssues($issues);

    expect($limited)->toHaveCount(3);
});

test('it prioritizes and limits in one operation', function () {
    config(['paladin.issues.max_per_run' => 2]);

    $issues = [
        ['id' => '1', 'severity' => 'low'],
        ['id' => '2', 'severity' => 'critical'],
        ['id' => '3', 'severity' => 'high'],
        ['id' => '4', 'severity' => 'medium'],
    ];

    $result = $this->prioritizer->prioritize($issues);

    expect($result)->toHaveCount(2);
    expect($result[0]['severity'])->toBe('critical');
    expect($result[1]['severity'])->toBe('high');
});

test('it gets severity rank for known severities', function () {
    expect($this->prioritizer->getSeverityRank('critical'))->toBe(1);
    expect($this->prioritizer->getSeverityRank('high'))->toBe(2);
    expect($this->prioritizer->getSeverityRank('medium'))->toBe(3);
    expect($this->prioritizer->getSeverityRank('low'))->toBe(4);
});

test('it returns high rank for unknown severities', function () {
    expect($this->prioritizer->getSeverityRank('unknown'))->toBe(999);
    expect($this->prioritizer->getSeverityRank(''))->toBe(999);
});

test('it handles empty issues array', function () {
    $result = $this->prioritizer->sortBySeverity([]);

    expect($result)->toBe([]);
});

test('it handles empty issues array for prioritize', function () {
    $result = $this->prioritizer->prioritize([]);

    expect($result)->toBe([]);
});

test('it maintains stable sort for same severity', function () {
    $issues = [
        ['id' => '1', 'severity' => 'high'],
        ['id' => '2', 'severity' => 'high'],
        ['id' => '3', 'severity' => 'high'],
    ];

    $sorted = $this->prioritizer->sortBySeverity($issues);

    expect($sorted[0]['id'])->toBe('1');
    expect($sorted[1]['id'])->toBe('2');
    expect($sorted[2]['id'])->toBe('3');
});
