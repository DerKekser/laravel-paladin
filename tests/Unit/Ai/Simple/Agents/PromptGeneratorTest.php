<?php

use Kekser\LaravelPaladin\Ai\Simple\Agents\PromptGenerator;

beforeEach(function () {
    $this->generator = app(PromptGenerator::class);
});

test('it generates prompt with issue details', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'ErrorException',
        'severity' => 'high',
        'title' => 'ErrorException: Undefined variable $user',
        'message' => 'ErrorException: Undefined variable $user in /app/Models/User.php:42',
        'stack_trace' => "#0 /app/Models/User.php(42): Illuminate\Foundation\Bootstrap\HandleExceptions->handleError()",
        'affected_files' => ['/app/Models/User.php'],
        'suggested_fix' => 'Define the missing variable or check for typos in variable names.',
        'log_level' => 'error',
    ];

    $prompt = $this->generator->generate($issue);

    expect($prompt)->toContain('Fix the following Laravel application issue');
    expect($prompt)->toContain('ISSUE DETAILS:');
    expect($prompt)->toContain('Type: ErrorException');
    expect($prompt)->toContain('Severity: high');
    expect($prompt)->toContain($issue['message']);
    expect($prompt)->toContain($issue['stack_trace']);
    expect($prompt)->toContain('/app/Models/User.php');
    expect($prompt)->toContain('REQUIREMENTS:');
});

test('it includes error message section', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'ErrorException',
        'severity' => 'high',
        'title' => 'Test title',
        'message' => 'Test error message here',
        'stack_trace' => '',
        'affected_files' => [],
        'suggested_fix' => '',
        'log_level' => 'error',
    ];

    $prompt = $this->generator->generate($issue);

    expect($prompt)->toContain('ERROR MESSAGE:');
    expect($prompt)->toContain('Test error message here');
});

test('it includes stack trace section', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'ErrorException',
        'severity' => 'high',
        'title' => 'Test title',
        'message' => 'Test message',
        'stack_trace' => "#0 /app/File.php(10): someFunction()\n#1 /app/Other.php(20): otherFunction()",
        'affected_files' => [],
        'suggested_fix' => '',
        'log_level' => 'error',
    ];

    $prompt = $this->generator->generate($issue);

    expect($prompt)->toContain('STACK TRACE:');
    expect($prompt)->toContain('/app/File.php');
    expect($prompt)->toContain('/app/Other.php');
});

test('it includes affected files section', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'ErrorException',
        'severity' => 'high',
        'title' => 'Test title',
        'message' => 'Test message',
        'stack_trace' => '',
        'affected_files' => [
            '/app/Models/User.php',
            '/app/Controllers/UserController.php',
        ],
        'suggested_fix' => '',
        'log_level' => 'error',
    ];

    $prompt = $this->generator->generate($issue);

    expect($prompt)->toContain('AFFECTED FILES:');
    expect($prompt)->toContain('- /app/Models/User.php');
    expect($prompt)->toContain('- /app/Controllers/UserController.php');
});

test('it includes suggested fix section', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'ErrorException',
        'severity' => 'high',
        'title' => 'Test title',
        'message' => 'Test message',
        'stack_trace' => '',
        'affected_files' => [],
        'suggested_fix' => 'Check variable declarations and ensure all variables are defined before use.',
        'log_level' => 'error',
    ];

    $prompt = $this->generator->generate($issue);

    expect($prompt)->toContain('SUGGESTED APPROACH:');
    expect($prompt)->toContain('Check variable declarations');
});

test('it includes test failure output when provided', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'ErrorException',
        'severity' => 'high',
        'title' => 'Test title',
        'message' => 'Test message',
        'stack_trace' => '',
        'affected_files' => [],
        'suggested_fix' => '',
        'log_level' => 'error',
    ];
    $testFailureOutput = 'FAILED: Test case XYZ\nExpected: foo\nActual: bar';

    $prompt = $this->generator->generate($issue, $testFailureOutput);

    expect($prompt)->toContain('PREVIOUS FIX ATTEMPT RESULTS:');
    expect($prompt)->toContain('FAILED: Test case XYZ');
    expect($prompt)->toContain('The previous fix did not resolve the issue');
});

test('it includes critical priority notice for critical severity', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'FatalError',
        'severity' => 'critical',
        'title' => 'Fatal error',
        'message' => 'Fatal error message',
        'stack_trace' => '',
        'affected_files' => [],
        'suggested_fix' => '',
        'log_level' => 'critical',
    ];

    $prompt = $this->generator->generate($issue);

    expect($prompt)->toContain('CRITICAL PRIORITY: This is a critical issue');
});

test('it includes high priority notice for high severity', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'ErrorException',
        'severity' => 'high',
        'title' => 'Test error',
        'message' => 'Test message',
        'stack_trace' => '',
        'affected_files' => [],
        'suggested_fix' => '',
        'log_level' => 'error',
    ];

    $prompt = $this->generator->generate($issue);

    expect($prompt)->toContain('HIGH PRIORITY: This is a high severity issue');
});

test('it includes database-specific instructions for database errors', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'DatabaseException',
        'severity' => 'high',
        'title' => 'Database error',
        'message' => 'SQL error',
        'stack_trace' => '',
        'affected_files' => [],
        'suggested_fix' => '',
        'log_level' => 'error',
    ];

    $prompt = $this->generator->generate($issue);

    expect($prompt)->toContain('DATABASE ERROR NOTES:');
    expect($prompt)->toContain('SQL syntax errors');
});

test('it includes HTTP-specific instructions for HTTP errors', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'HttpException',
        'severity' => 'high',
        'title' => 'HTTP error',
        'message' => '404 Not Found',
        'stack_trace' => '',
        'affected_files' => [],
        'suggested_fix' => '',
        'log_level' => 'error',
    ];

    $prompt = $this->generator->generate($issue);

    expect($prompt)->toContain('HTTP/ROUTE ERROR NOTES:');
    expect($prompt)->toContain('route definitions');
});

test('it includes validation-specific instructions for validation errors', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'ValidationException',
        'severity' => 'medium',
        'title' => 'Validation error',
        'message' => 'Validation failed',
        'stack_trace' => '',
        'affected_files' => [],
        'suggested_fix' => '',
        'log_level' => 'error',
    ];

    $prompt = $this->generator->generate($issue);

    expect($prompt)->toContain('VALIDATION ERROR NOTES:');
    expect($prompt)->toContain('validation rules');
});

test('it includes type-specific instructions for type errors', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'TypeError',
        'severity' => 'high',
        'title' => 'Type error',
        'message' => 'Type mismatch',
        'stack_trace' => '',
        'affected_files' => [],
        'suggested_fix' => '',
        'log_level' => 'error',
    ];

    $prompt = $this->generator->generate($issue);

    expect($prompt)->toContain('TYPE ERROR NOTES:');
    expect($prompt)->toContain('function/method signatures');
});

test('it includes instructions for undefined errors', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'UndefinedVariableException',
        'severity' => 'high',
        'title' => 'Undefined variable',
        'message' => 'Undefined variable',
        'stack_trace' => '',
        'affected_files' => [],
        'suggested_fix' => '',
        'log_level' => 'error',
    ];

    $prompt = $this->generator->generate($issue);

    expect($prompt)->toContain('UNDEFINED ERROR NOTES:');
    expect($prompt)->toContain('typos in variable');
});

test('it includes model-specific instructions for model errors', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'ModelNotFoundException',
        'severity' => 'high',
        'title' => 'Model not found',
        'message' => 'Model not found',
        'stack_trace' => '',
        'affected_files' => [],
        'suggested_fix' => '',
        'log_level' => 'error',
    ];

    $prompt = $this->generator->generate($issue);

    expect($prompt)->toContain('MODEL ERROR NOTES:');
    expect($prompt)->toContain('model class exists');
});

test('it includes auth-specific instructions for auth errors', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'AuthenticationException',
        'severity' => 'high',
        'title' => 'Auth error',
        'message' => 'Unauthenticated',
        'stack_trace' => '',
        'affected_files' => [],
        'suggested_fix' => '',
        'log_level' => 'error',
    ];

    $prompt = $this->generator->generate($issue);

    expect($prompt)->toContain('AUTHENTICATION/AUTHORIZATION NOTES:');
    expect($prompt)->toContain('authentication middleware');
});

test('it includes file-specific instructions for file errors', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'FileNotFoundException',
        'severity' => 'high',
        'title' => 'File not found',
        'message' => 'File not found',
        'stack_trace' => '',
        'affected_files' => [],
        'suggested_fix' => '',
        'log_level' => 'error',
    ];

    $prompt = $this->generator->generate($issue);

    expect($prompt)->toContain('FILE ERROR NOTES:');
    expect($prompt)->toContain('file paths');
});

test('it includes view-specific instructions for view errors', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'ViewException',
        'severity' => 'high',
        'title' => 'View error',
        'message' => 'View not found',
        'stack_trace' => '',
        'affected_files' => [],
        'suggested_fix' => '',
        'log_level' => 'error',
    ];

    $prompt = $this->generator->generate($issue);

    expect($prompt)->toContain('VIEW ERROR NOTES:');
    expect($prompt)->toContain('view file exists');
});

test('it includes generic instructions for unknown error types', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'UnknownError',
        'severity' => 'medium',
        'title' => 'Unknown error',
        'message' => 'Something went wrong',
        'stack_trace' => '',
        'affected_files' => [],
        'suggested_fix' => '',
        'log_level' => 'error',
    ];

    $prompt = $this->generator->generate($issue);

    // Should still have requirements
    expect($prompt)->toContain('REQUIREMENTS:');
    // Should not have type-specific notes
    expect($prompt)->not->toContain('DATABASE ERROR NOTES:');
    expect($prompt)->not->toContain('HTTP/ROUTE ERROR NOTES:');
});

test('it handles empty optional fields gracefully', function () {
    $issue = [
        'id' => 'test-id',
        'type' => 'ErrorException',
        'severity' => 'high',
        'title' => 'Test title',
        'message' => '',
        'stack_trace' => '',
        'affected_files' => [],
        'suggested_fix' => '',
        'log_level' => 'error',
    ];

    $prompt = $this->generator->generate($issue);

    expect($prompt)->toContain('Fix the following Laravel application issue');
    expect($prompt)->toContain('REQUIREMENTS:');
});
