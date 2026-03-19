<?php

use Kekser\LaravelPaladin\Ai\Concerns\InteractsWithIssuePrompts;

/**
 * A dummy class to test the trait.
 */
class InteractsWithIssuePromptsTester
{
    use InteractsWithIssuePrompts;

    public function testBuildIssueContext(array $issue, ?string $testFailureOutput = null): string
    {
        return $this->buildIssueContext($issue, $testFailureOutput);
    }
}

beforeEach(function () {
    $this->tester = app(InteractsWithIssuePromptsTester::class);
});

it('builds basic issue context', function () {
    $issue = [
        'type' => 'Error',
        'severity' => 'high',
        'title' => 'Test Issue',
        'message' => 'Something went wrong',
    ];

    $result = $this->tester->testBuildIssueContext($issue);

    expect($result)->toContain('**Issue Type**: Error');
    expect($result)->toContain('**Severity**: high');
    expect($result)->toContain('**Title**: Test Issue');
    expect($result)->toContain("**Error Message**:\nSomething went wrong");
    expect($result)->not->toContain('**Affected Files**:');
    expect($result)->not->toContain('**Stack Trace**:');
    expect($result)->not->toContain('**Suggested Fix**:');
    expect($result)->not->toContain('**Previous Fix Attempt Failed**');
});

it('includes affected files in context', function () {
    $issue = [
        'type' => 'Error',
        'severity' => 'high',
        'title' => 'Test Issue',
        'message' => 'Something went wrong',
        'affected_files' => ['app/Models/User.php', 'app/Http/Controllers/UserController.php'],
    ];

    $result = $this->tester->testBuildIssueContext($issue);

    expect($result)->toContain('**Affected Files**:');
    expect($result)->toContain('- app/Models/User.php');
    expect($result)->toContain('- app/Http/Controllers/UserController.php');
});

it('includes stack trace in context', function () {
    $issue = [
        'type' => 'Error',
        'severity' => 'high',
        'title' => 'Test Issue',
        'message' => 'Something went wrong',
        'stack_trace' => '#0 /path/to/file.php(10): doSomething()',
    ];

    $result = $this->tester->testBuildIssueContext($issue);

    expect($result)->toContain('**Stack Trace**:');
    expect($result)->toContain('#0 /path/to/file.php(10): doSomething()');
});

it('includes suggested fix in context', function () {
    $issue = [
        'type' => 'Error',
        'severity' => 'high',
        'title' => 'Test Issue',
        'message' => 'Something went wrong',
        'suggested_fix' => 'Try fixing the database connection.',
    ];

    $result = $this->tester->testBuildIssueContext($issue);

    expect($result)->toContain('**Suggested Fix**:');
    expect($result)->toContain('Try fixing the database connection.');
});

it('includes test failure output in context', function () {
    $issue = [
        'type' => 'Error',
        'severity' => 'high',
        'title' => 'Test Issue',
        'message' => 'Something went wrong',
    ];
    $testFailureOutput = 'PHPUnit failed: 1) test_it_works';

    $result = $this->tester->testBuildIssueContext($issue, $testFailureOutput);

    expect($result)->toContain('**Previous Fix Attempt Failed**');
    expect($result)->toContain('The previous fix attempt resulted in test failures.');
    expect($result)->toContain('```'."\n".'PHPUnit failed: 1) test_it_works'."\n".'```');
});
