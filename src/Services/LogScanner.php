<?php

namespace Kekser\LaravelPaladin\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class LogScanner
{
    /**
     * Storage path for log files.
     */
    protected string $storagePath;

    /**
     * Log channels to scan.
     */
    protected array $channels;

    /**
     * Log levels to include.
     */
    protected array $levels;

    /**
     * Patterns to ignore.
     */
    protected array $ignorePatterns;

    /**
     * File boundary validator.
     */
    protected FileBoundaryValidator $boundaryValidator;

    /**
     * Log state storage service.
     */
    protected LogStateStorage $stateStorage;

    public function __construct()
    {
        $this->storagePath = config('paladin.log.storage_path');

        // Handle both string and array channel configuration
        $channels = config('paladin.log.channels');
        $this->channels = is_array($channels) ? $channels : explode(',', $channels);

        $this->levels = config('paladin.log.levels');
        $this->ignorePatterns = config('paladin.issues.ignore_patterns', []);
        $this->boundaryValidator = app(FileBoundaryValidator::class);
        $this->stateStorage = app(LogStateStorage::class);
    }

    /**
     * Scan log files for new entries since last scan.
     * Uses streaming for memory efficiency and position-based tracking.
     */
    public function scan(): array
    {
        $entries = [];

        foreach ($this->channels as $channel) {
            $channel = trim($channel);
            $logFile = $this->getLogFilePath($channel);

            if (! File::exists($logFile)) {
                continue;
            }

            $newEntries = $this->parseLogFile($logFile);
            $entries = array_merge($entries, $newEntries);
        }

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
     * Parse log file and extract new entries using streaming.
     * Tracks file position to avoid re-processing old entries.
     */
    protected function parseLogFile(string $filePath): array
    {
        $entries = [];
        $state = $this->stateStorage->getState($filePath);

        // Check if file has been rotated or replaced
        if ($this->stateStorage->hasFileRotated($filePath, $state)) {
            Log::info('[Paladin] Log file rotated, resetting state', ['file' => $filePath]);
            $state = $this->stateStorage->getDefaultState();
        }

        // Get current file stats
        $fileStats = $this->getFileStats($filePath);
        $currentSize = $fileStats['size'];

        // If file hasn't grown, nothing new to read
        if ($currentSize <= $state['position']) {
            return $entries;
        }

        // Open file for reading with streaming
        $file = new \SplFileObject($filePath, 'r');

        // Seek to last read position
        $file->fseek($state['position']);

        $currentEntry = null;
        $lastPosition = $state['position'];

        while (! $file->eof()) {
            $line = $file->fgets();
            if ($line === false) {
                break;
            }

            // Track position before processing this line
            $lastPosition = $file->ftell();

            // Remove trailing newline for consistent parsing
            $line = rtrim($line, "\r\n");

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

                // Only include if level matches
                if (in_array($level, $this->levels)) {
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

        // Update state with new position
        $newState = array_merge($state, [
            'position' => $lastPosition,
            'size' => $currentSize,
            'inode' => $fileStats['inode'],
            'mtime' => $fileStats['mtime'],
        ]);

        $this->stateStorage->saveState($filePath, $newState);

        return $entries;
    }

    /**
     * Get file statistics for rotation detection.
     */
    protected function getFileStats(string $filePath): array
    {
        $stat = stat($filePath);

        return [
            'size' => $stat['size'] ?? 0,
            'inode' => $stat['ino'] ?? null,
            'mtime' => $stat['mtime'] ?? null,
        ];
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
     * Reset state for a specific log file.
     */
    public function resetState(string $channel): void
    {
        $filePath = $this->getLogFilePath($channel);
        $this->stateStorage->resetState($filePath);
    }

    /**
     * Reset all log states.
     */
    public function resetAllStates(): void
    {
        $this->stateStorage->resetAllStates();
    }

    /**
     * @deprecated Use resetAllStates() instead
     */
    public function resetLastScanTime(): void
    {
        Cache::forget('paladin.last_scan_time');
        $this->resetAllStates();
    }
}
