<?php

use Illuminate\Support\Facades\Cache;
use Kekser\LaravelPaladin\Services\LogScanner;

beforeEach(function () {
    $this->tempLogPath = $this->createTempDirectory('log-scanner-test-');
    config(['paladin.log.storage_path' => $this->tempLogPath]);
    config(['paladin.log.channels' => 'stack']);
    config(['paladin.log.levels' => ['error', 'critical', 'alert', 'emergency']]);
    config(['paladin.issues.ignore_patterns' => []]);

    $this->scanner = app(LogScanner::class);

    // Reset cache before each test
    Cache::forget('paladin.last_scan_time');
});

afterEach(function () {
    if (isset($this->tempLogPath) && is_dir($this->tempLogPath)) {
        $this->deleteDirectory($this->tempLogPath);
    }
});

function createLogFile(string $tempLogPath, string $filename, string $content): void
{
    $path = $tempLogPath.'/'.$filename;
    file_put_contents($path, $content);
}

test('it can scan single line log entries', function () {
    createLogFile($this->tempLogPath, 'laravel.log',
        '[2026-03-15 10:23:45] production.ERROR: Division by zero'
    );

    $entries = $this->scanner->scan();

    expect($entries)->toHaveCount(1);
    expect($entries[0]['level'])->toBe('error');
    expect($entries[0]['message'])->toBe('Division by zero');
});

test('it can parse multi line log entries with stack traces', function () {
    $logContent = '[2026-03-15 10:23:45] production.ERROR: Division by zero {"exception":"[object] (DivisionByZeroError)"}
[stacktrace]
#0 /var/www/app/Http/Controllers/UserController.php(42): calculate()
#1 /var/www/app/Http/Controllers/UserController.php(25): index()';

    createLogFile($this->tempLogPath, 'laravel.log', $logContent);

    $entries = $this->scanner->scan();

    expect($entries)->toHaveCount(1);
    expect($entries[0]['message'])->toBe('Division by zero {"exception":"[object] (DivisionByZeroError)"}');
    expect($entries[0]['stack_trace'])->toContain('#0 /var/www/app/Http/Controllers/UserController.php(42)');
    expect($entries[0]['stack_trace'])->toContain('#1 /var/www/app/Http/Controllers/UserController.php(25)');
});

test('it only scans entries after last scan time', function () {
    // Set last scan time to 1 hour ago
    $lastScanTime = time() - 3600;
    Cache::put('paladin.last_scan_time', $lastScanTime);

    // Create log with old and new entries
    $oldTimestamp = date('Y-m-d H:i:s', $lastScanTime - 60);
    $newTimestamp = date('Y-m-d H:i:s', $lastScanTime + 60);

    $logContent = "[{$oldTimestamp}] production.ERROR: Old error\n[{$newTimestamp}] production.ERROR: New error";
    createLogFile($this->tempLogPath, 'laravel.log', $logContent);

    $entries = $this->scanner->scan();

    expect($entries)->toHaveCount(1);
    expect($entries[0]['message'])->toBe('New error');
});

test('it filters by log levels', function () {
    config(['paladin.log.levels' => ['error', 'critical']]);
    $this->scanner = app(LogScanner::class);

    $logContent = "[2026-03-15 10:00:00] production.ERROR: Error message\n"
        ."[2026-03-15 10:01:00] production.CRITICAL: Critical message\n"
        ."[2026-03-15 10:02:00] production.WARNING: Warning message\n"
        .'[2026-03-15 10:03:00] production.INFO: Info message';

    createLogFile($this->tempLogPath, 'laravel.log', $logContent);

    $entries = $this->scanner->scan();

    expect($entries)->toHaveCount(2);
    $levels = array_column($entries, 'level');
    expect($levels)->toContain('error');
    expect($levels)->toContain('critical');
    expect($levels)->not->toContain('warning');
    expect($levels)->not->toContain('info');
});

test('it applies ignore patterns', function () {
    config(['paladin.issues.ignore_patterns' => [
        '/Package .* is abandoned/',
        '/deprecated/i',
    ]]);
    $this->scanner = app(LogScanner::class);

    $logContent = "[2026-03-15 10:00:00] production.ERROR: Normal error\n"
        ."[2026-03-15 10:01:00] production.ERROR: Package foo/bar is abandoned\n"
        ."[2026-03-15 10:02:00] production.ERROR: Using deprecated function\n"
        .'[2026-03-15 10:03:00] production.ERROR: Another normal error';

    createLogFile($this->tempLogPath, 'laravel.log', $logContent);

    $entries = $this->scanner->scan();

    expect($entries)->toHaveCount(2);
    $messages = array_column($entries, 'message');
    expect($messages)->toContain('Normal error');
    expect($messages)->toContain('Another normal error');
    expect($messages)->not->toContain('Package foo/bar is abandoned');
});

test('it deduplicates similar entries', function () {
    // Same error from same file and line should be deduplicated
    $logContent = "[2026-03-15 10:00:00] production.ERROR: Division by zero in UserController.php:42\n"
        ."[2026-03-15 10:01:00] production.ERROR: Division by zero in UserController.php:42\n"
        .'[2026-03-15 10:02:00] production.ERROR: Different error';

    createLogFile($this->tempLogPath, 'laravel.log', $logContent);

    $entries = $this->scanner->scan();

    // Should only have 2 entries (1 deduplicated + 1 unique)
    expect($entries)->toHaveCount(2);
});

test('it generates unique hashes for entries', function () {
    $logContent = "[2026-03-15 10:00:00] production.ERROR: Error one\n"
        ."[2026-03-15 10:01:00] production.ERROR: Error two\n"
        .'[2026-03-15 10:02:00] production.CRITICAL: Error one';

    createLogFile($this->tempLogPath, 'laravel.log', $logContent);

    $entries = $this->scanner->scan();

    expect($entries)->toHaveCount(3);

    // Each entry should have a hash
    foreach ($entries as $entry) {
        expect($entry)->toHaveKey('hash');
        expect($entry['hash'])->not->toBeEmpty();
    }

    // Different entries should have different hashes
    $hashes = array_column($entries, 'hash');
    expect(array_unique($hashes))->toHaveCount(3);
});

test('it can scan multiple log channels', function () {
    config(['paladin.log.channels' => ['stack', 'single']]);
    $this->scanner = app(LogScanner::class);

    createLogFile($this->tempLogPath, 'laravel.log', '[2026-03-15 10:00:00] production.ERROR: Error in laravel.log');
    createLogFile($this->tempLogPath, 'single.log', '[2026-03-15 10:01:00] production.ERROR: Error in single.log');

    $entries = $this->scanner->scan();

    expect($entries)->toHaveCount(2);
    $messages = array_column($entries, 'message');
    expect($messages)->toContain('Error in laravel.log');
    expect($messages)->toContain('Error in single.log');
});

test('it handles comma separated channel configuration', function () {
    config(['paladin.log.channels' => 'stack,single,daily']);
    $this->scanner = app(LogScanner::class);

    createLogFile($this->tempLogPath, 'laravel.log', '[2026-03-15 10:00:00] production.ERROR: Stack error');
    createLogFile($this->tempLogPath, 'single.log', '[2026-03-15 10:01:00] production.ERROR: Single error');
    createLogFile($this->tempLogPath, 'daily.log', '[2026-03-15 10:02:00] production.ERROR: Daily error');

    $entries = $this->scanner->scan();

    expect($entries)->toHaveCount(3);
});

test('it handles nonexistent log files gracefully', function () {
    config(['paladin.log.channels' => ['stack', 'nonexistent']]);
    $this->scanner = app(LogScanner::class);

    createLogFile($this->tempLogPath, 'laravel.log', '[2026-03-15 10:00:00] production.ERROR: Error message');
    // Don't create 'nonexistent.log'

    $entries = $this->scanner->scan();

    // Should only get entry from existing file
    expect($entries)->toHaveCount(1);
    expect($entries[0]['message'])->toBe('Error message');
});

test('it handles empty log files', function () {
    createLogFile($this->tempLogPath, 'laravel.log', '');

    $entries = $this->scanner->scan();

    expect($entries)->toHaveCount(0);
});

test('it updates last scan time after scanning', function () {
    createLogFile($this->tempLogPath, 'laravel.log', '[2026-03-15 10:00:00] production.ERROR: Test error');

    expect(Cache::has('paladin.last_scan_time'))->toBeFalse();

    $this->scanner->scan();

    expect(Cache::has('paladin.last_scan_time'))->toBeTrue();
    $lastScanTime = Cache::get('paladin.last_scan_time');
    expect($lastScanTime)->toBeGreaterThan(0);
    expect($lastScanTime)->toBeLessThanOrEqual(time());
});

test('it can reset last scan time', function () {
    Cache::put('paladin.last_scan_time', time() - 3600);
    expect(Cache::has('paladin.last_scan_time'))->toBeTrue();

    $this->scanner->resetLastScanTime();

    expect(Cache::has('paladin.last_scan_time'))->toBeFalse();
});

test('it maps stack channel to laravel log', function () {
    config(['paladin.log.channels' => 'stack']);
    $this->scanner = app(LogScanner::class);

    createLogFile($this->tempLogPath, 'laravel.log', '[2026-03-15 10:00:00] production.ERROR: Test error');

    $entries = $this->scanner->scan();

    expect($entries)->toHaveCount(1);
});

test('it generates unique hashes for entries including file and line', function () {
    $logContent = "[2026-03-15 10:00:00] production.ERROR: Error one\n"
        ."[stacktrace]\n"
        ."#0 /path/to/File.php(42): doSomething()\n"
        ."in /path/to/File.php:42\n"
        ."[2026-03-15 10:01:00] production.ERROR: Error one\n"
        ."[stacktrace]\n"
        ."#0 /path/to/Other.php(10): doSomething()\n"
        .'in /path/to/Other.php:10';

    createLogFile($this->tempLogPath, 'laravel.log', $logContent);

    $entries = $this->scanner->scan();

    expect($entries)->toHaveCount(2);
    expect($entries[0]['hash'])->not->toBe($entries[1]['hash']);
});

test('it handles non-stack channel names', function () {
    config(['paladin.log.channels' => ['custom']]);
    $this->scanner = app(LogScanner::class);

    createLogFile($this->tempLogPath, 'custom.log', '[2026-03-15 10:00:00] production.ERROR: Custom error');

    $entries = $this->scanner->scan();

    expect($entries)->toHaveCount(1);
    expect($entries[0]['message'])->toBe('Custom error');
});

test('it includes raw log line in entry', function () {
    $logLine = '[2026-03-15 10:23:45] production.ERROR: Division by zero';
    createLogFile($this->tempLogPath, 'laravel.log', $logLine);

    $entries = $this->scanner->scan();

    expect($entries)->toHaveCount(1);
    expect($entries[0])->toHaveKey('raw');
    expect($entries[0]['raw'])->toBe($logLine);
});

test('it extracts timestamp correctly', function () {
    $logLine = '[2026-03-15 10:23:45] production.ERROR: Test error';
    createLogFile($this->tempLogPath, 'laravel.log', $logLine);

    $entries = $this->scanner->scan();

    expect($entries)->toHaveCount(1);
    expect($entries[0])->toHaveKey('timestamp');
    expect($entries[0]['timestamp'])->toBe(strtotime('2026-03-15 10:23:45'));
});

test('it handles different log environments', function () {
    $logContent = "[2026-03-15 10:00:00] production.ERROR: Production error\n"
        ."[2026-03-15 10:01:00] local.ERROR: Local error\n"
        .'[2026-03-15 10:02:00] staging.CRITICAL: Staging error';

    createLogFile($this->tempLogPath, 'laravel.log', $logContent);

    $entries = $this->scanner->scan();

    expect($entries)->toHaveCount(3);
    // All environments should be captured
    expect($entries[0]['message'])->toBe('Production error');
    expect($entries[1]['message'])->toBe('Local error');
    expect($entries[2]['message'])->toBe('Staging error');
});

test('it filters out entries from external files', function () {
    // Create a log entry with a stack trace containing only external files
    $logContent = '[2026-03-15 10:23:45] production.ERROR: External error
[stacktrace]
#0 /run/media/benjamin/e7dc58d5-bf0d-4df3-ab78-5274a582caa6/Source/LaravelPaladin/vendor/laravel/framework/src/Illuminate/Container/Container.php(780): resolve()';

    createLogFile($this->tempLogPath, 'laravel.log', $logContent);

    // Mock FileBoundaryValidator to return non-fixable for this stack trace
    // Since we can't easily inject the mock into LogScanner (it's instantiated in constructor),
    // we'll rely on the real FileBoundaryValidator which should identify vendor files as external.

    $entries = $this->scanner->scan();

    expect($entries)->toBeEmpty();
});

test('it includes entries with project files in stack trace', function () {
    // Create a log entry with a stack trace containing project files
    $logContent = '[2026-03-15 10:23:45] production.ERROR: Project error
[stacktrace]
#0 /run/media/benjamin/e7dc58d5-bf0d-4df3-ab78-5274a582caa6/Source/LaravelPaladin/src/Services/LogScanner.php(42): scan()';

    createLogFile($this->tempLogPath, 'laravel.log', $logContent);

    $entries = $this->scanner->scan();

    expect($entries)->toHaveCount(1);
    expect($entries[0]['message'])->toBe('Project error');
});
