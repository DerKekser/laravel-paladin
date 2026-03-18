<?php

use Kekser\LaravelPaladin\Models\HealingAttempt;

test('it displays overall statistics', function () {
    HealingAttempt::create([
        'issue_id' => 'ISSUE-1',
        'issue_type' => 'Exception',
        'severity' => 'error',
        'status' => 'fixed',
        'message' => 'Fixed a bug',
    ]);

    HealingAttempt::create([
        'issue_id' => 'ISSUE-2',
        'issue_type' => 'Error',
        'severity' => 'critical',
        'status' => 'failed',
        'message' => 'Failed to fix',
    ]);

    HealingAttempt::create([
        'issue_id' => 'ISSUE-3',
        'issue_type' => 'Warning',
        'severity' => 'warning',
        'status' => 'pending',
        'message' => 'Pending fix',
    ]);

    $this->artisan('paladin:status')
        ->expectsOutputToContain('Overall Statistics')
        ->expectsOutputToContain('Total Attempts: 3')
        ->expectsOutputToContain('Fixed: 1')
        ->expectsOutputToContain('Failed: 1')
        ->expectsOutputToContain('Pending: 1')
        ->assertExitCode(0);
});

test('it displays recent attempts in compact mode by default', function () {
    $attempt = HealingAttempt::create([
        'issue_id' => 'ISSUE-1',
        'issue_type' => 'Exception',
        'severity' => 'error',
        'status' => 'fixed',
        'message' => 'Fixed a bug',
        'pr_url' => 'https://github.com/test/repo/pull/1',
    ]);

    $this->artisan('paladin:status')
        ->expectsOutputToContain('Recent Attempts')
        ->expectsOutputToContain("#{$attempt->id}")
        ->assertExitCode(0);
});

test('it filters attempts by status', function () {
    $attemptFixed = HealingAttempt::create([
        'issue_id' => 'ISSUE-FIXED',
        'issue_type' => 'Exception',
        'severity' => 'error',
        'status' => 'fixed',
        'message' => 'Fixed a bug',
    ]);

    $attemptFailed = HealingAttempt::create([
        'issue_id' => 'ISSUE-FAILED',
        'issue_type' => 'Error',
        'severity' => 'critical',
        'status' => 'failed',
        'message' => 'Failed to fix',
    ]);

    $this->artisan('paladin:status --status=fixed')
        ->expectsOutputToContain("#{$attemptFixed->id}")
        ->assertExitCode(0);
});

test('it limits the number of recent attempts', function () {
    for ($i = 1; $i <= 15; $i++) {
        HealingAttempt::create([
            'issue_id' => "ISSUE-$i",
            'issue_type' => 'Exception',
            'severity' => 'error',
            'status' => 'pending',
            'message' => "Message $i",
        ]);
    }

    $this->artisan('paladin:status')
        ->assertExitCode(0);

    $this->artisan('paladin:status --limit=5')
        ->assertExitCode(0);
});

test('it displays verbose details for attempts', function () {
    $attempt = HealingAttempt::create([
        'issue_id' => 'ISSUE-VERBOSE',
        'issue_type' => 'Exception',
        'severity' => 'error',
        'status' => 'failed',
        'message' => 'Verbose failure message',
        'error_message' => 'Detailed error message',
        'stack_trace' => "Line 1\nLine 2\nLine 3",
        'affected_files' => ['app/Models/User.php', 'tests/Feature/UserTest.php'],
    ]);

    $this->artisan('paladin:status --details')
        ->expectsOutputToContain("#{$attempt->id}")
        ->expectsOutputToContain('Verbose failure message')
        ->expectsOutputToContain('Detailed error message')
        ->assertExitCode(0);
});

test('it handles empty attempts', function () {
    $this->artisan('paladin:status')
        ->expectsOutputToContain('No healing attempts found.')
        ->assertExitCode(0);
});
