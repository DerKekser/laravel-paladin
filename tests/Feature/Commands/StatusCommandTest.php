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

test('it handles invalid status filter', function () {
    $this->artisan('paladin:status --status=invalid')
        ->expectsOutputToContain('Invalid status filter')
        ->assertExitCode(1);
});

test('it filters by all statuses', function ($status) {
    HealingAttempt::create([
        'issue_id' => "ISSUE-$status",
        'issue_type' => 'Exception',
        'severity' => 'error',
        'status' => $status,
        'message' => "Message for $status",
    ]);

    $this->artisan("paladin:status --status=$status")
        ->expectsOutputToContain($status)
        ->assertExitCode(0);
})->with(['pending', 'in_progress', 'fixed', 'failed']);

test('it shows compact details for all statuses', function ($status, $details, $expectedOutput) {
    HealingAttempt::create(array_merge([
        'issue_id' => "ISSUE-$status",
        'issue_type' => 'Exception',
        'severity' => 'error',
        'status' => $status,
        'message' => "Message for $status",
    ], $details));

    $this->artisan('paladin:status')
        ->assertExitCode(0);
})->with([
    ['fixed', ['pr_url' => 'https://github.com/test/repo/pull/123'], 'test/repo#123'],
    ['fixed', ['pr_url' => 'https://other-vcs.com/some/long/url/that/needs/truncating'], 'https://other-vcs.com/some/long/url/that/needs/t...'],
    ['in_progress', ['worktree_path' => '/tmp/worktree'], 'Working...'],
    ['failed', ['error_message' => 'Something went wrong'], 'Something went wrong'],
    ['failed', ['error_message' => 'A very long error message that should be truncated to forty characters or more'], 'A very long error message that should b...'],
    ['pending', [], 'Queued'],
    ['unknown', ['status' => 'skipped'], '-'],
]);

test('it displays all optional fields in verbose mode', function () {
    HealingAttempt::create([
        'issue_id' => 'ISSUE-ALL',
        'issue_type' => 'FullException',
        'severity' => 'critical',
        'status' => 'fixed',
        'message' => 'A complete message',
        'affected_files' => ['file1.php', 'file2.php'],
        'worktree_path' => '/path/to/worktree',
        'branch_name' => 'fix-branch',
        'pr_url' => 'https://github.com/org/repo/pull/456',
        'error_message' => 'No error actually, but testing the field',
        'attempt_number' => 2,
        'stack_trace' => "Trace line 1\nTrace line 2",
    ]);

    $this->artisan('paladin:status --details')
        ->expectsOutputToContain('ID: #1')
        ->expectsOutputToContain('Status: ✓ fixed')
        ->expectsOutputToContain('Issue Type: FullException')
        ->expectsOutputToContain('Severity: critical')
        ->expectsOutputToContain('Message: A complete message')
        ->expectsOutputToContain('Affected Files: file1.php, file2.php')
        ->expectsOutputToContain('Worktree Path: /path/to/worktree')
        ->expectsOutputToContain('Branch Name: fix-branch')
        ->expectsOutputToContain('PR URL: https://github.com/org/repo/pull/456')
        ->expectsOutputToContain('Error: No error actually, but testing the field')
        ->expectsOutputToContain('Attempt Number: 2')
        ->expectsOutputToContain('Stack Trace:')
        ->expectsOutputToContain('Trace line 1')
        ->assertExitCode(0);
});

test('it displays multiple attempts with separators in verbose mode', function () {
    HealingAttempt::create([
        'issue_id' => 'ISSUE-1',
        'issue_type' => 'Exception',
        'severity' => 'error',
        'status' => 'fixed',
        'message' => 'First message',
    ]);

    HealingAttempt::create([
        'issue_id' => 'ISSUE-2',
        'issue_type' => 'Exception',
        'severity' => 'error',
        'status' => 'fixed',
        'message' => 'Second message',
    ]);

    $this->artisan('paladin:status --details')
        ->expectsOutputToContain('First message')
        ->expectsOutputToContain('Second message')
        ->expectsOutputToContain('────────────────────────────────────────────────────────────────────────────────')
        ->assertExitCode(0);
});

test('it truncates long stack traces', function () {
    $stackTrace = '';
    for ($i = 1; $i <= 15; $i++) {
        $stackTrace .= "Line $i\n";
    }
    $stackTrace = trim($stackTrace);

    HealingAttempt::create([
        'issue_id' => 'ISSUE-STACK',
        'issue_type' => 'Exception',
        'severity' => 'error',
        'status' => 'failed',
        'message' => 'Stack trace issue',
        'stack_trace' => $stackTrace,
    ]);

    $this->artisan('paladin:status --details')
        ->expectsOutputToContain('... and 5 more lines')
        ->assertExitCode(0);
});
