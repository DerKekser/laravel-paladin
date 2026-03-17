<?php

use Kekser\LaravelPaladin\Ai\Concerns\InteractsWithLogEntries;

test('it formats log entries', function () {
    $trait = new class {
        use InteractsWithLogEntries {
            formatLogEntries as public;
        }
    };

    $logEntries = [
        [
            'timestamp' => 1710681600, // 2024-03-17 13:20:00
            'level' => 'error',
            'message' => 'Something went wrong',
            'stack_trace' => '#0 /path/to/file.php(10): doSomething()',
        ],
        [
            'timestamp' => 1710681660, // 2024-03-17 13:21:00
            'level' => 'critical',
            'message' => 'Critical failure',
            'stack_trace' => '#0 /path/to/other.php(20): explode()',
        ],
    ];

    $result = $trait->formatLogEntries($logEntries);

    expect($result)->toContain('[2024-03-17 13:20:00] ERROR: Something went wrong')
        ->toContain('#0 /path/to/file.php(10): doSomething()')
        ->toContain('---')
        ->toContain('[2024-03-17 13:21:00] CRITICAL: Critical failure')
        ->toContain('#0 /path/to/other.php(20): explode()');
});

test('it handles missing fields', function () {
    $trait = new class {
        use InteractsWithLogEntries {
            formatLogEntries as public;
        }
    };

    $logEntries = [
        []
    ];

    $result = $trait->formatLogEntries($logEntries);

    expect($result)->toContain('ERROR: No message');
});
