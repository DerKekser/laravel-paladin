<?php

use Kekser\LaravelPaladin\Models\HealingAttempt;

test('it can create a healing attempt', function () {
    $attempt = HealingAttempt::create([
        'issue_id' => 'test-123',
        'issue_type' => 'runtime_error',
        'severity' => 'high',
        'message' => 'Test error message',
        'stack_trace' => 'Test stack trace',
        'affected_files' => ['app/Test.php'],
        'attempt_number' => 1,
    ]);

    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'test-123',
        'issue_type' => 'runtime_error',
        'severity' => 'high',
        'status' => 'pending',
    ]);

    expect($attempt->issue_id)->toBe('test-123');
    expect($attempt->status)->toBe('pending');
    expect($attempt->affected_files)->toBe(['app/Test.php']);
});

test('it casts affected files to array', function () {
    $attempt = HealingAttempt::create([
        'issue_id' => 'test-456',
        'issue_type' => 'syntax_error',
        'severity' => 'critical',
        'message' => 'Syntax error',
        'affected_files' => ['file1.php', 'file2.php', 'file3.php'],
    ]);

    expect($attempt->affected_files)->toBeArray();
    expect($attempt->affected_files)->toHaveCount(3);
    expect($attempt->affected_files)->toBe(['file1.php', 'file2.php', 'file3.php']);

    // Reload from database to ensure casting works
    $reloaded = HealingAttempt::find($attempt->id);
    expect($reloaded->affected_files)->toBeArray();
    expect($reloaded->affected_files)->toBe(['file1.php', 'file2.php', 'file3.php']);
});

test('it can mark attempt as in progress', function () {
    $attempt = HealingAttempt::create([
        'issue_id' => 'test-789',
        'issue_type' => 'runtime_error',
        'severity' => 'medium',
        'message' => 'Test message',
    ]);

    expect($attempt->status)->toBe('pending');

    $attempt->markAsInProgress();

    expect($attempt->fresh()->status)->toBe('in_progress');
    $this->assertDatabaseHas('healing_attempts', [
        'id' => $attempt->id,
        'status' => 'in_progress',
    ]);
});

test('it can mark attempt as fixed', function () {
    $attempt = HealingAttempt::create([
        'issue_id' => 'test-101',
        'issue_type' => 'runtime_error',
        'severity' => 'high',
        'message' => 'Test message',
        'status' => 'in_progress',
    ]);

    $prUrl = 'https://github.com/test/repo/pull/42';
    $attempt->markAsFixed($prUrl);

    expect($attempt->fresh()->status)->toBe('fixed');
    expect($attempt->fresh()->pr_url)->toBe($prUrl);
    $this->assertDatabaseHas('healing_attempts', [
        'id' => $attempt->id,
        'status' => 'fixed',
        'pr_url' => $prUrl,
    ]);
});

test('it can mark attempt as fixed without pr url', function () {
    $attempt = HealingAttempt::create([
        'issue_id' => 'test-102',
        'issue_type' => 'runtime_error',
        'severity' => 'high',
        'message' => 'Test message',
        'status' => 'in_progress',
    ]);

    $attempt->markAsFixed();

    expect($attempt->fresh()->status)->toBe('fixed');
    expect($attempt->fresh()->pr_url)->toBeNull();
});

test('it can mark attempt as failed', function () {
    $attempt = HealingAttempt::create([
        'issue_id' => 'test-103',
        'issue_type' => 'runtime_error',
        'severity' => 'high',
        'message' => 'Test message',
        'status' => 'in_progress',
    ]);

    $errorMessage = 'Tests failed after fix';
    $attempt->markAsFailed($errorMessage);

    expect($attempt->fresh()->status)->toBe('failed');
    expect($attempt->fresh()->error_message)->toBe($errorMessage);
    $this->assertDatabaseHas('healing_attempts', [
        'id' => $attempt->id,
        'status' => 'failed',
        'error_message' => $errorMessage,
    ]);
});

test('it can mark attempt as failed without error message', function () {
    $attempt = HealingAttempt::create([
        'issue_id' => 'test-104',
        'issue_type' => 'runtime_error',
        'severity' => 'high',
        'message' => 'Test message',
        'status' => 'in_progress',
    ]);

    $attempt->markAsFailed();

    expect($attempt->fresh()->status)->toBe('failed');
    expect($attempt->fresh()->error_message)->toBeNull();
});

test('it can filter by pending status', function () {
    HealingAttempt::create(['issue_id' => 'test-1', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'pending']);
    HealingAttempt::create(['issue_id' => 'test-2', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'in_progress']);
    HealingAttempt::create(['issue_id' => 'test-3', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'pending']);
    HealingAttempt::create(['issue_id' => 'test-4', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'fixed']);

    $pending = HealingAttempt::pending()->get();

    expect($pending)->toHaveCount(2);
    expect($pending->every(fn ($a) => $a->status === 'pending'))->toBeTrue();
});

test('it can filter by in progress status', function () {
    HealingAttempt::create(['issue_id' => 'test-1', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'pending']);
    HealingAttempt::create(['issue_id' => 'test-2', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'in_progress']);
    HealingAttempt::create(['issue_id' => 'test-3', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'in_progress']);

    $inProgress = HealingAttempt::inProgress()->get();

    expect($inProgress)->toHaveCount(2);
    expect($inProgress->every(fn ($a) => $a->status === 'in_progress'))->toBeTrue();
});

test('it can filter by fixed status', function () {
    HealingAttempt::create(['issue_id' => 'test-1', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'pending']);
    HealingAttempt::create(['issue_id' => 'test-2', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'fixed']);
    HealingAttempt::create(['issue_id' => 'test-3', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'fixed']);
    HealingAttempt::create(['issue_id' => 'test-4', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'failed']);

    $fixed = HealingAttempt::fixed()->get();

    expect($fixed)->toHaveCount(2);
    expect($fixed->every(fn ($a) => $a->status === 'fixed'))->toBeTrue();
});

test('it can filter by failed status', function () {
    HealingAttempt::create(['issue_id' => 'test-1', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'failed']);
    HealingAttempt::create(['issue_id' => 'test-2', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'fixed']);
    HealingAttempt::create(['issue_id' => 'test-3', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'failed']);

    $failed = HealingAttempt::failed()->get();

    expect($failed)->toHaveCount(2);
    expect($failed->every(fn ($a) => $a->status === 'failed'))->toBeTrue();
});

test('it can filter by issue id', function () {
    HealingAttempt::create(['issue_id' => 'issue-123', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'attempt_number' => 1]);
    HealingAttempt::create(['issue_id' => 'issue-123', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'attempt_number' => 2]);
    HealingAttempt::create(['issue_id' => 'issue-456', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'attempt_number' => 1]);
    HealingAttempt::create(['issue_id' => 'issue-123', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'attempt_number' => 3]);

    $attempts = HealingAttempt::byIssueId('issue-123')->get();

    expect($attempts)->toHaveCount(3);
    expect($attempts->every(fn ($a) => $a->issue_id === 'issue-123'))->toBeTrue();
});

test('it can chain scopes', function () {
    HealingAttempt::create(['issue_id' => 'issue-100', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'pending', 'attempt_number' => 1]);
    HealingAttempt::create(['issue_id' => 'issue-100', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'fixed', 'attempt_number' => 2]);
    HealingAttempt::create(['issue_id' => 'issue-100', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'failed', 'attempt_number' => 3]);
    HealingAttempt::create(['issue_id' => 'issue-200', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'fixed']);

    $attempts = HealingAttempt::byIssueId('issue-100')->fixed()->get();

    expect($attempts)->toHaveCount(1);
    expect($attempts->first()->issue_id)->toBe('issue-100');
    expect($attempts->first()->status)->toBe('fixed');
});

test('it has timestamps', function () {
    $attempt = HealingAttempt::create([
        'issue_id' => 'test-timestamps',
        'issue_type' => 'runtime_error',
        'severity' => 'high',
        'message' => 'Test message',
    ]);

    expect($attempt->created_at)->not->toBeNull();
    expect($attempt->updated_at)->not->toBeNull();
    $this->assertEquals($attempt->created_at->timestamp, $attempt->updated_at->timestamp, '', 1);
});

test('it updates timestamp on status change', function () {
    $attempt = HealingAttempt::create([
        'issue_id' => 'test-update',
        'issue_type' => 'runtime_error',
        'severity' => 'high',
        'message' => 'Test message',
    ]);

    $originalUpdatedAt = $attempt->updated_at;

    // Wait a moment to ensure timestamp difference
    sleep(1);

    $attempt->markAsInProgress();

    expect($attempt->fresh()->updated_at->timestamp)->not->toBe($originalUpdatedAt->timestamp);
});

test('it defaults status to pending', function () {
    $attempt = HealingAttempt::create([
        'issue_id' => 'test-default',
        'issue_type' => 'runtime_error',
        'severity' => 'high',
        'message' => 'Test message',
    ]);

    expect($attempt->status)->toBe('pending');
});

test('it defaults attempt number to one', function () {
    $attempt = HealingAttempt::create([
        'issue_id' => 'test-attempt-default',
        'issue_type' => 'runtime_error',
        'severity' => 'high',
        'message' => 'Test message',
    ]);

    expect($attempt->attempt_number)->toBe(1);
});
