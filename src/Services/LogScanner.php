<?php

namespace Kekser\LaravelPaladin\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class LogScanner
{
    protected string $storagePath;

    protected array $channels;

    protected array $levels;

    protected array $ignorePatterns;

    protected FileBoundaryValidator $boundaryValidator;

    public function __construct()
    {
        $this->storagePath = config('paladin.log.storage_path');

        // Handle both string and array channel configuration
        $channels = config('paladin.log.channels');
        $this->channels = is_array($channels) ? $channels : explode(',', $channels);

        $this->levels = config('paladin.log.levels');
        $this->ignorePatterns = config('paladin.issues.ignore_patterns', []);
        $this->boundaryValidator = app(FileBoundaryValidator::class);
    }

    /**
     * Scan log files for new entries since last scan.
     */
    public function scan(): array
    {
        $lastScanTime = $this->getLastScanTime();
        $entries = [];

        foreach ($this->channels as $channel) {
            $channel = trim($channel);
            $logFile = $this->getLogFilePath($channel);

            if (! File::exists($logFile)) {
                continue;
            }

            $newEntries = $this->parseLogFile($logFile, $lastScanTime);
            $entries = array_merge($entries, $newEntries);
        }

        $this->updateLastScanTime();

        return $this->filterAndDeduplicate($entries);
    }

    /**
     * Get the path to a log file for a given channel.
     */
    protected function getLogFilePath(string $channel): string
    {
        // The 'stack' channel is a meta-channel that combines others,
        // so we default to laravel.log for it
        if ($channel === 'stack') {
            return $this->storagePath.'/laravel.log';
        }

        return $this->storagePath.'/'.$channel.'.log';
    }

    /**
     * Parse log file and extract entries newer than the given timestamp.
     */
    protected function parseLogFile(string $filePath, int $lastScanTime): array
    {
        $entries = [];

        $content = File::get($filePath);
        $lines = explode("\n", $content);

        $currentEntry = null;

        foreach ($lines as $line) {
            // Match Laravel log format: [YYYY-MM-DD HH:MM:SS] environment.LEVEL: message
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+\w+\.(\w+):\s+(.*)$/', $line, $matches)) {
                // Save previous entry if exists
                if ($currentEntry && $this->shouldIncludeEntry($currentEntry)) {
                    $entries[] = $currentEntry;
                }

                // Start new entry
                $timestamp = strtotime($matches[1]);
                $level = strtolower($matches[2]);
                $message = $matches[3];

                // Only include if timestamp is after last scan and level matches
                if ($timestamp > $lastScanTime && in_array($level, $this->levels)) {
                    $currentEntry = [
                        'timestamp' => $timestamp,
                        'level' => $level,
                        'message' => $message,
                        'stack_trace' => '',
                        'raw' => $line,
                    ];
                } else {
                    $currentEntry = null;
                }
            } elseif ($currentEntry) {
                // Continuation of current entry (stack trace, etc.)
                $currentEntry['stack_trace'] .= $line."\n";
                $currentEntry['raw'] .= "\n".$line;
            }
        }

        // Add last entry if exists
        if ($currentEntry && $this->shouldIncludeEntry($currentEntry)) {
            $entries[] = $currentEntry;
        }

        return $entries;
    }

    /**
     * Check if entry should be included based on ignore patterns.
     */
    protected function shouldIncludeEntry(array $entry): bool
    {
        foreach ($this->ignorePatterns as $pattern) {
            if (preg_match($pattern, $entry['message']) || preg_match($pattern, $entry['stack_trace'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Filter and deduplicate log entries.
     * Also performs early boundary validation to skip entries from external files.
     */
    protected function filterAndDeduplicate(array $entries): array
    {
        $unique = [];

        foreach ($entries as $entry) {
            // Early detection: Extract files from stack trace before AI processing
            $stackTrace = $entry['stack_trace'] ?? '';
            if (! empty($stackTrace)) {
                $affectedFiles = $this->boundaryValidator->extractFilesFromStackTrace($stackTrace);

                // Skip if all affected files are external
                if (! empty($affectedFiles)) {
                    $validation = $this->boundaryValidator->analyzeIssue($affectedFiles);

                    if (! $validation['is_fixable']) {
                        // Log that we're skipping this entry before AI processing
                        Log::debug('[Paladin] Skipping log entry - all files external', [
                            'message' => substr($entry['message'], 0, 100),
                            'external_files' => $validation['external_files'],
                        ]);

                        continue; // Skip this entry entirely
                    }
                }
            }

            $hash = $this->generateEntryHash($entry);

            if (! isset($unique[$hash])) {
                $entry['hash'] = $hash;
                $unique[$hash] = $entry;
            }
        }

        return array_values($unique);
    }

    /**
     * Generate a hash for an entry to detect duplicates.
     */
    protected function generateEntryHash(array $entry): string
    {
        // Extract exception class and line number if present
        $message = $entry['message'];
        $stackTrace = $entry['stack_trace'] ?? '';

        // Try to extract file and line from stack trace
        if (preg_match('/in (.+):(\d+)/', $stackTrace, $matches)) {
            $file = basename($matches[1]);
            $line = $matches[2];

            return md5($entry['level'].$message.$file.$line);
        }

        return md5($entry['level'].$message);
    }

    /**
     * Get the timestamp of the last scan.
     */
    protected function getLastScanTime(): int
    {
        return Cache::get('paladin.last_scan_time', 0);
    }

    /**
     * Update the last scan timestamp.
     */
    protected function updateLastScanTime(): void
    {
        Cache::put('paladin.last_scan_time', time(), now()->addDays(30));
    }

    /**
     * Reset the last scan time (useful for testing or manual re-scanning).
     */
    public function resetLastScanTime(): void
    {
        Cache::forget('paladin.last_scan_time');
    }
}
