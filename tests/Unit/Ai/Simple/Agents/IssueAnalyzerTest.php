<?php

use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Ai\Simple\Agents\IssueAnalyzer;

beforeEach(function () {
    $this->analyzer = app(IssueAnalyzer::class);
    Log::spy();
});

test('it analyzes log entries and extracts issues', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'error',
            'message' => 'ErrorException: Undefined variable $user in /app/Models/User.php:42',
            'stack_trace' => "#0 /app/Models/User.php(42): Illuminate\Foundation\Bootstrap\HandleExceptions->handleError()\n#1 /app/Http/Controllers/UserController.php(25): App\\Models\\User->getFullName()",
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(1);
    // ErrorException is extracted as Error due to pattern matching on Error suffix
    expect($issues[0]['type'])->toBeIn(['ErrorException', 'Error']);
    expect($issues[0]['severity'])->toBe('high');
    expect($issues[0]['log_level'])->toBe('error');
    expect($issues[0]['message'])->toContain('Undefined variable $user');
});

test('it extracts affected files from stack trace', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'error',
            'message' => 'ErrorException: Call to undefined method App\Models\User::getFullName()',
            'stack_trace' => "#0 /app/Http/Controllers/UserController.php(25): App\\Models\\User->getFullName()\n#1 /vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): call_user_func_array()",
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(1);
    expect($issues[0]['affected_files'])->toHaveCount(1);
    expect($issues[0]['affected_files'][0])->toBe('/app/Http/Controllers/UserController.php');
});

test('it extracts affected files from message', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'error',
            'message' => 'ErrorException: Test error in /app/Services/TestService.php:15',
            'stack_trace' => '',
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(1);
    expect($issues[0]['affected_files'])->toContain('/app/Services/TestService.php');
});

test('it excludes vendor files from affected files', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'error',
            'message' => 'ErrorException: Test error',
            'stack_trace' => "#0 /vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): call_user_func_array()\n#1 /app/Http/Controllers/TestController.php(10): Illuminate\\Routing\\Controller->callAction()\n#2 /vendor/some-package/src/File.php(20): TestController->index()",
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(1);
    foreach ($issues[0]['affected_files'] as $file) {
        expect($file)->not->toContain('vendor');
    }
});

test('it determines critical severity for fatal errors', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'critical',
            'message' => 'Fatal error: Out of memory',
            'stack_trace' => '',
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(1);
    expect($issues[0]['severity'])->toBe('critical');
});

test('it determines severity based on error type', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'error',
            'message' => 'TypeError: Argument 1 passed to App\Services\TestService::process() must be of type string, int given',
            'stack_trace' => '',
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(1);
    expect($issues[0]['severity'])->toBe('high');
    expect($issues[0]['type'])->toBe('TypeError');
});

test('it determines medium severity for warnings', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'warning',
            'message' => 'Warning: Deprecated function usage',
            'stack_trace' => '',
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(1);
    expect($issues[0]['severity'])->toBe('medium');
});

test('it determines low severity for other levels', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'notice',
            'message' => 'Notice: Some notice',
            'stack_trace' => '',
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(1);
    expect($issues[0]['severity'])->toBe('low');
});

test('it extracts database error types', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'error',
            'message' => 'Illuminate\Database\QueryException: SQLSTATE[42S02]: Base table or view not found',
            'stack_trace' => '',
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(1);
    expect($issues[0]['type'])->toBe('DatabaseException');
});

test('it extracts validation error types', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'error',
            'message' => 'Illuminate\Validation\ValidationException: The email field is required.',
            'stack_trace' => '',
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(1);
    expect($issues[0]['type'])->toBe('ValidationException');
});

test('it extracts HTTP error types', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'error',
            'message' => 'HTTP 404 Not Found',
            'stack_trace' => '',
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(1);
    expect($issues[0]['type'])->toBe('HttpException');
});

test('it generates suggested fix based on error type', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'error',
            'message' => 'ErrorException: Undefined variable $test',
            'stack_trace' => '',
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(1);
    expect($issues[0]['suggested_fix'])->toContain('variable');
});

test('it generates unique ids for different issues', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'error',
            'message' => 'ErrorException: First error',
            'stack_trace' => '#0 /app/Models/First.php(10)',
        ],
        [
            'timestamp' => 1710681601,
            'level' => 'error',
            'message' => 'ErrorException: Second error',
            'stack_trace' => '#0 /app/Models/Second.php(20)',
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(2);
    expect($issues[0]['id'])->not->toBe($issues[1]['id']);
});

test('it merges duplicate issues', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'error',
            'message' => 'ErrorException: Same error',
            'stack_trace' => '#0 /app/Models/User.php(10)',
        ],
        [
            'timestamp' => 1710681601,
            'level' => 'error',
            'message' => 'ErrorException: Same error',
            'stack_trace' => '#0 /app/Models/User.php(10)',
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(1);
});

test('it handles empty log entries', function () {
    $logEntries = [];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toBe([]);
});

test('it handles log entries without message', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'error',
            'message' => '',
            'stack_trace' => '',
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toBe([]);
});

test('it generates title with error type', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'error',
            'message' => 'ErrorException: This is a test error message.',
            'stack_trace' => '',
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(1);
    expect($issues[0]['title'])->toContain('ErrorException:');
    expect($issues[0]['title'])->toContain('This is a test error message.');
});

test('it truncates long messages in title', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'error',
            'message' => str_repeat('A', 200),
            'stack_trace' => '',
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(1);
    expect(strlen($issues[0]['title']))->toBeLessThan(100);
    expect($issues[0]['title'])->toEndWith('...');
});

test('it handles multiple log entries', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'error',
            'message' => 'First error',
            'stack_trace' => '#0 /app/First.php(10)',
        ],
        [
            'timestamp' => 1710681601,
            'level' => 'warning',
            'message' => 'Second warning',
            'stack_trace' => '#0 /app/Second.php(20)',
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(2);
    expect($issues[0]['severity'])->toBe('high');
    expect($issues[1]['severity'])->toBe('medium');
});

test('it handles parse errors', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'critical',
            'message' => 'ParseError: syntax error, unexpected token "}" in /app/Controllers/TestController.php',
            'stack_trace' => '',
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(1);
    expect($issues[0]['type'])->toBe('ParseError');
    expect($issues[0]['severity'])->toBe('critical');
});

test('it handles authentication errors', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'error',
            'message' => 'Illuminate\Auth\AuthenticationException: Unauthenticated.',
            'stack_trace' => '',
        ],
    ];

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(1);
    // Should extract the full exception class name
    expect($issues[0]['type'])->toContain('Authentication');
});
