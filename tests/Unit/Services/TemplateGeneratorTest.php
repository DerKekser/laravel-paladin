<?php

use Kekser\LaravelPaladin\Services\TemplateGenerator;

beforeEach(function () {
    $this->generator = new TemplateGenerator;

    config([
        'paladin.git.branch_prefix' => 'paladin/fix',
        'paladin.git.commit_message_template' => 'Fix: {issue_title} ({severity})',
        'paladin.git.pr_title_template' => '[Paladin] Fix: {issue_title}',
        'paladin.git.pr_body_template' => "## Issue\nType: {issue_type}\nSeverity: {severity}\nFiles: {affected_files}\n\nDescription:\n{issue_description}\n\nStack Trace:\n{stack_trace}",
    ]);
});

test('it generates commit message from template', function () {
    $issue = [
        'title' => 'Database Connection Error',
        'message' => 'Cannot connect to database',
        'severity' => 'high',
    ];

    $message = $this->generator->generateCommitMessage($issue, 1, 3);

    expect($message)->toBe('Fix: Database Connection Error (high)');
});

test('it generates PR title from template', function () {
    $issue = [
        'title' => 'Database Connection Error',
    ];

    $title = $this->generator->generatePRTitle($issue);

    expect($title)->toBe('[Paladin] Fix: Database Connection Error');
});

test('it generates PR body from template', function () {
    $issue = [
        'type' => 'runtime_error',
        'severity' => 'critical',
        'affected_files' => ['app/Database/Connection.php', 'config/database.php'],
        'message' => 'Connection refused',
        'stack_trace' => 'Stack trace line 1\nStack trace line 2',
    ];

    $body = $this->generator->generatePRBody($issue, 1, 3);

    expect($body)->toContain('Type: runtime_error');
    expect($body)->toContain('Severity: CRITICAL');
    expect($body)->toContain('app/Database/Connection.php, config/database.php');
    expect($body)->toContain('Connection refused');
    expect($body)->toContain('Stack trace line 1');
});

test('it uses N/A for missing stack trace', function () {
    $issue = [
        'type' => 'error',
        'severity' => 'high',
        'affected_files' => [],
        'message' => 'Test',
    ];

    $body = $this->generator->generatePRBody($issue, 1, 3);

    expect($body)->toContain('Stack Trace:');
    expect($body)->toContain('N/A');
});

test('it generates branch name from issue id', function () {
    $issue = [
        'id' => 'abc123def456',
    ];

    $branchName = $this->generator->generateBranchName($issue);

    expect($branchName)->toBe('paladin/fix-abc123de');
});

test('it includes attempt number in commit message', function () {
    config(['paladin.git.commit_message_template' => 'Fix: {issue_title} (attempt {attempt_number}/{max_attempts})']);

    $issue = [
        'title' => 'Test Issue',
        'message' => 'Test',
        'severity' => 'high',
    ];

    $message = $this->generator->generateCommitMessage($issue, 2, 5);

    expect($message)->toBe('Fix: Test Issue (attempt 2/5)');
});

test('it handles empty values gracefully', function () {
    $issue = [
        'title' => '',
        'message' => '',
        'severity' => '',
    ];

    $message = $this->generator->generateCommitMessage($issue, 1, 1);

    expect($message)->toBe('Fix:  ()');
});

test('it handles missing optional fields', function () {
    $issue = [
        'title' => 'Test',
    ];

    $message = $this->generator->generateCommitMessage($issue, 1, 1);

    expect($message)->toBe('Fix: Test ()');
});

test('it uses default branch prefix when not configured', function () {
    config(['paladin.git.branch_prefix' => null]);

    $issue = [
        'id' => 'test123',
    ];

    $branchName = $this->generator->generateBranchName($issue);

    expect($branchName)->toBe('paladin/fix-test123');
});
