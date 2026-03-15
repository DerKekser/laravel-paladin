<?php

namespace Kekser\LaravelPaladin\Tests\Unit\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Kekser\LaravelPaladin\Services\LogScanner;
use Kekser\LaravelPaladin\Tests\TestCase;

class LogScannerTest extends TestCase
{
    protected LogScanner $scanner;

    protected string $tempLogPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempLogPath = $this->createTempDirectory('log-scanner-test-');
        config(['paladin.log.storage_path' => $this->tempLogPath]);
        config(['paladin.log.channels' => 'stack']);
        config(['paladin.log.levels' => ['error', 'critical', 'alert', 'emergency']]);
        config(['paladin.issues.ignore_patterns' => []]);

        $this->scanner = new LogScanner;

        // Reset cache before each test
        Cache::forget('paladin.last_scan_time');
    }

    protected function tearDown(): void
    {
        if (isset($this->tempLogPath) && is_dir($this->tempLogPath)) {
            $this->deleteDirectory($this->tempLogPath);
        }

        parent::tearDown();
    }

    /** @test */
    public function it_can_scan_single_line_log_entries()
    {
        $this->createLogFile('laravel.log',
            '[2026-03-15 10:23:45] production.ERROR: Division by zero'
        );

        $entries = $this->scanner->scan();

        $this->assertCount(1, $entries);
        $this->assertEquals('error', $entries[0]['level']);
        $this->assertEquals('Division by zero', $entries[0]['message']);
    }

    /** @test */
    public function it_can_parse_multi_line_log_entries_with_stack_traces()
    {
        $logContent = '[2026-03-15 10:23:45] production.ERROR: Division by zero {"exception":"[object] (DivisionByZeroError)"}
[stacktrace]
#0 /var/www/app/Http/Controllers/UserController.php(42): calculate()
#1 /var/www/app/Http/Controllers/UserController.php(25): index()';

        $this->createLogFile('laravel.log', $logContent);

        $entries = $this->scanner->scan();

        $this->assertCount(1, $entries);
        $this->assertEquals('Division by zero {"exception":"[object] (DivisionByZeroError)"}', $entries[0]['message']);
        $this->assertStringContainsString('#0 /var/www/app/Http/Controllers/UserController.php(42)', $entries[0]['stack_trace']);
        $this->assertStringContainsString('#1 /var/www/app/Http/Controllers/UserController.php(25)', $entries[0]['stack_trace']);
    }

    /** @test */
    public function it_only_scans_entries_after_last_scan_time()
    {
        // Set last scan time to 1 hour ago
        $lastScanTime = time() - 3600;
        Cache::put('paladin.last_scan_time', $lastScanTime);

        // Create log with old and new entries
        $oldTimestamp = date('Y-m-d H:i:s', $lastScanTime - 60);
        $newTimestamp = date('Y-m-d H:i:s', $lastScanTime + 60);

        $logContent = "[{$oldTimestamp}] production.ERROR: Old error\n[{$newTimestamp}] production.ERROR: New error";
        $this->createLogFile('laravel.log', $logContent);

        $entries = $this->scanner->scan();

        $this->assertCount(1, $entries);
        $this->assertEquals('New error', $entries[0]['message']);
    }

    /** @test */
    public function it_filters_by_log_levels()
    {
        config(['paladin.log.levels' => ['error', 'critical']]);
        $this->scanner = new LogScanner;

        $logContent = "[2026-03-15 10:00:00] production.ERROR: Error message\n"
            ."[2026-03-15 10:01:00] production.CRITICAL: Critical message\n"
            ."[2026-03-15 10:02:00] production.WARNING: Warning message\n"
            .'[2026-03-15 10:03:00] production.INFO: Info message';

        $this->createLogFile('laravel.log', $logContent);

        $entries = $this->scanner->scan();

        $this->assertCount(2, $entries);
        $levels = array_column($entries, 'level');
        $this->assertContains('error', $levels);
        $this->assertContains('critical', $levels);
        $this->assertNotContains('warning', $levels);
        $this->assertNotContains('info', $levels);
    }

    /** @test */
    public function it_applies_ignore_patterns()
    {
        config(['paladin.issues.ignore_patterns' => [
            '/Package .* is abandoned/',
            '/deprecated/i',
        ]]);
        $this->scanner = new LogScanner;

        $logContent = "[2026-03-15 10:00:00] production.ERROR: Normal error\n"
            ."[2026-03-15 10:01:00] production.ERROR: Package foo/bar is abandoned\n"
            ."[2026-03-15 10:02:00] production.ERROR: Using deprecated function\n"
            .'[2026-03-15 10:03:00] production.ERROR: Another normal error';

        $this->createLogFile('laravel.log', $logContent);

        $entries = $this->scanner->scan();

        $this->assertCount(2, $entries);
        $messages = array_column($entries, 'message');
        $this->assertContains('Normal error', $messages);
        $this->assertContains('Another normal error', $messages);
        $this->assertNotContains('Package foo/bar is abandoned', $messages);
    }

    /** @test */
    public function it_deduplicates_similar_entries()
    {
        // Same error from same file and line should be deduplicated
        $logContent = "[2026-03-15 10:00:00] production.ERROR: Division by zero in UserController.php:42\n"
            ."[2026-03-15 10:01:00] production.ERROR: Division by zero in UserController.php:42\n"
            .'[2026-03-15 10:02:00] production.ERROR: Different error';

        $this->createLogFile('laravel.log', $logContent);

        $entries = $this->scanner->scan();

        // Should only have 2 entries (1 deduplicated + 1 unique)
        $this->assertCount(2, $entries);
    }

    /** @test */
    public function it_generates_unique_hashes_for_entries()
    {
        $logContent = "[2026-03-15 10:00:00] production.ERROR: Error one\n"
            ."[2026-03-15 10:01:00] production.ERROR: Error two\n"
            .'[2026-03-15 10:02:00] production.CRITICAL: Error one';

        $this->createLogFile('laravel.log', $logContent);

        $entries = $this->scanner->scan();

        $this->assertCount(3, $entries);

        // Each entry should have a hash
        foreach ($entries as $entry) {
            $this->assertArrayHasKey('hash', $entry);
            $this->assertNotEmpty($entry['hash']);
        }

        // Different entries should have different hashes
        $hashes = array_column($entries, 'hash');
        $this->assertCount(3, array_unique($hashes));
    }

    /** @test */
    public function it_can_scan_multiple_log_channels()
    {
        config(['paladin.log.channels' => ['stack', 'single']]);
        $this->scanner = new LogScanner;

        $this->createLogFile('laravel.log', '[2026-03-15 10:00:00] production.ERROR: Error in laravel.log');
        $this->createLogFile('single.log', '[2026-03-15 10:01:00] production.ERROR: Error in single.log');

        $entries = $this->scanner->scan();

        $this->assertCount(2, $entries);
        $messages = array_column($entries, 'message');
        $this->assertContains('Error in laravel.log', $messages);
        $this->assertContains('Error in single.log', $messages);
    }

    /** @test */
    public function it_handles_comma_separated_channel_configuration()
    {
        config(['paladin.log.channels' => 'stack,single,daily']);
        $this->scanner = new LogScanner;

        $this->createLogFile('laravel.log', '[2026-03-15 10:00:00] production.ERROR: Stack error');
        $this->createLogFile('single.log', '[2026-03-15 10:01:00] production.ERROR: Single error');
        $this->createLogFile('daily.log', '[2026-03-15 10:02:00] production.ERROR: Daily error');

        $entries = $this->scanner->scan();

        $this->assertCount(3, $entries);
    }

    /** @test */
    public function it_handles_nonexistent_log_files_gracefully()
    {
        config(['paladin.log.channels' => ['stack', 'nonexistent']]);
        $this->scanner = new LogScanner;

        $this->createLogFile('laravel.log', '[2026-03-15 10:00:00] production.ERROR: Error message');
        // Don't create 'nonexistent.log'

        $entries = $this->scanner->scan();

        // Should only get entry from existing file
        $this->assertCount(1, $entries);
        $this->assertEquals('Error message', $entries[0]['message']);
    }

    /** @test */
    public function it_handles_empty_log_files()
    {
        $this->createLogFile('laravel.log', '');

        $entries = $this->scanner->scan();

        $this->assertCount(0, $entries);
    }

    /** @test */
    public function it_updates_last_scan_time_after_scanning()
    {
        $this->createLogFile('laravel.log', '[2026-03-15 10:00:00] production.ERROR: Test error');

        $this->assertFalse(Cache::has('paladin.last_scan_time'));

        $this->scanner->scan();

        $this->assertTrue(Cache::has('paladin.last_scan_time'));
        $lastScanTime = Cache::get('paladin.last_scan_time');
        $this->assertGreaterThan(0, $lastScanTime);
        $this->assertLessThanOrEqual(time(), $lastScanTime);
    }

    /** @test */
    public function it_can_reset_last_scan_time()
    {
        Cache::put('paladin.last_scan_time', time() - 3600);
        $this->assertTrue(Cache::has('paladin.last_scan_time'));

        $this->scanner->resetLastScanTime();

        $this->assertFalse(Cache::has('paladin.last_scan_time'));
    }

    /** @test */
    public function it_maps_stack_channel_to_laravel_log()
    {
        config(['paladin.log.channels' => 'stack']);
        $this->scanner = new LogScanner;

        $this->createLogFile('laravel.log', '[2026-03-15 10:00:00] production.ERROR: Test error');

        $entries = $this->scanner->scan();

        $this->assertCount(1, $entries);
    }

    /** @test */
    public function it_includes_raw_log_line_in_entry()
    {
        $logLine = '[2026-03-15 10:23:45] production.ERROR: Division by zero';
        $this->createLogFile('laravel.log', $logLine);

        $entries = $this->scanner->scan();

        $this->assertCount(1, $entries);
        $this->assertArrayHasKey('raw', $entries[0]);
        $this->assertEquals($logLine, $entries[0]['raw']);
    }

    /** @test */
    public function it_extracts_timestamp_correctly()
    {
        $logLine = '[2026-03-15 10:23:45] production.ERROR: Test error';
        $this->createLogFile('laravel.log', $logLine);

        $entries = $this->scanner->scan();

        $this->assertCount(1, $entries);
        $this->assertArrayHasKey('timestamp', $entries[0]);
        $this->assertEquals(strtotime('2026-03-15 10:23:45'), $entries[0]['timestamp']);
    }

    /** @test */
    public function it_handles_different_log_environments()
    {
        $logContent = "[2026-03-15 10:00:00] production.ERROR: Production error\n"
            ."[2026-03-15 10:01:00] local.ERROR: Local error\n"
            .'[2026-03-15 10:02:00] staging.CRITICAL: Staging error';

        $this->createLogFile('laravel.log', $logContent);

        $entries = $this->scanner->scan();

        $this->assertCount(3, $entries);
        // All environments should be captured
        $this->assertEquals('Production error', $entries[0]['message']);
        $this->assertEquals('Local error', $entries[1]['message']);
        $this->assertEquals('Staging error', $entries[2]['message']);
    }

    /** @test */
    public function it_handles_malformed_log_entries_gracefully()
    {
        $logContent = "[2026-03-15 10:00:00] production.ERROR: Valid error\n"
            ."This is a malformed line without proper formatting\n"
            .'[2026-03-15 10:01:00] production.ERROR: Another valid error';

        $this->createLogFile('laravel.log', $logContent);

        $entries = $this->scanner->scan();

        // Should only capture valid entries
        $this->assertCount(2, $entries);
        $this->assertEquals('Valid error', $entries[0]['message']);
        $this->assertEquals('Another valid error', $entries[1]['message']);
    }

    /**
     * Helper method to create a log file in the temp directory.
     */
    protected function createLogFile(string $filename, string $content): void
    {
        $path = $this->tempLogPath.'/'.$filename;
        file_put_contents($path, $content);
    }
}
