<?php

namespace Kekser\LaravelPaladin\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class LogStateStorage
{
    /**
     * Cache key prefix for log state storage.
     */
    protected const CACHE_KEY_PREFIX = 'paladin.log_state.';

    /**
     * State file name.
     */
    protected const STATE_FILE_NAME = 'paladin-log-state.json';

    /**
     * Cache TTL in days.
     */
    protected int $cacheTtl;

    /**
     * State file path.
     */
    protected string $stateFilePath;

    public function __construct()
    {
        $this->cacheTtl = config('paladin.log.state_cache_ttl', 30);
        $this->stateFilePath = storage_path($this::STATE_FILE_NAME);
    }

    /**
     * Get the state for a specific log file.
     * Tries cache first, then falls back to file storage.
     */
    public function getState(string $filePath): array
    {
        $cacheKey = $this->getCacheKey($filePath);

        // Try cache first (fast)
        $cachedState = Cache::get($cacheKey);
        if ($cachedState !== null) {
            return $cachedState;
        }

        // Fall back to file storage (secure)
        $fileState = $this->getStateFromFile($filePath);
        if ($fileState !== null) {
            // Sync back to cache for next time
            Cache::put($cacheKey, $fileState, now()->addDays($this->cacheTtl));

            return $fileState;
        }

        // No state found - return default
        return $this->getDefaultState();
    }

    /**
     * Save state for a specific log file.
     * Saves to both cache and file for redundancy.
     */
    public function saveState(string $filePath, array $state): void
    {
        $cacheKey = $this->getCacheKey($filePath);

        // Add file metadata for rotation detection
        $state['last_updated'] = time();
        $state['file_path'] = $filePath;

        // Save to cache (fast)
        Cache::put($cacheKey, $state, now()->addDays($this->cacheTtl));

        // Track this file for resetAllStates
        $this->trackFile($filePath);

        // Save to file (secure)
        $this->saveStateToFile($filePath, $state);
    }

    /**
     * Check if log file has been rotated or replaced.
     * Returns true if file has changed (new inode, smaller size, etc.).
     */
    public function hasFileRotated(string $filePath, array $state): bool
    {
        if (! File::exists($filePath)) {
            return true;
        }

        $currentStats = $this->getFileStats($filePath);

        // Check if file was replaced (different inode or mtime older than last read)
        if (isset($state['inode']) && $state['inode'] !== $currentStats['inode']) {
            Log::debug('[Paladin] Log file rotated (inode changed)', [
                'file' => $filePath,
                'old_inode' => $state['inode'],
                'new_inode' => $currentStats['inode'],
            ]);

            return true;
        }

        // Check if file is smaller than last known size (log rotation with compression)
        if (isset($state['size']) && $currentStats['size'] < $state['size']) {
            Log::debug('[Paladin] Log file rotated (size decreased)', [
                'file' => $filePath,
                'old_size' => $state['size'],
                'new_size' => $currentStats['size'],
            ]);

            return true;
        }

        // Check if mtime is significantly different (file was replaced)
        if (isset($state['mtime']) && $state['mtime'] > $currentStats['mtime']) {
            Log::debug('[Paladin] Log file rotated (mtime changed)', [
                'file' => $filePath,
                'old_mtime' => $state['mtime'],
                'new_mtime' => $currentStats['mtime'],
            ]);

            return true;
        }

        return false;
    }

    /**
     * Reset state for a specific log file.
     */
    public function resetState(string $filePath): void
    {
        $cacheKey = $this->getCacheKey($filePath);
        Cache::forget($cacheKey);

        $this->removeStateFromFile($filePath);
    }

    /**
     * Reset all log states.
     */
    public function resetAllStates(): void
    {
        // Clear all cache entries with our prefix
        // Note: Laravel's Cache doesn't have a delete by prefix method,
        // so we use a workaround - store a list of tracked files

        // Get tracked files list if it exists
        $trackedKey = $this::CACHE_KEY_PREFIX.'_tracked_files';
        $trackedFiles = Cache::get($trackedKey, []);

        // Clear cache for each tracked file
        foreach ($trackedFiles as $filePath) {
            $cacheKey = $this->getCacheKey($filePath);
            Cache::forget($cacheKey);
        }

        // Clear the tracked files list itself
        Cache::forget($trackedKey);

        // Remove state file
        if (File::exists($this->stateFilePath)) {
            File::delete($this->stateFilePath);
        }

        Log::info('[Paladin] All log states reset');
    }

    /**
     * Track a file in the tracked files list.
     */
    protected function trackFile(string $filePath): void
    {
        $trackedKey = $this::CACHE_KEY_PREFIX.'_tracked_files';
        $trackedFiles = Cache::get($trackedKey, []);

        if (! in_array($filePath, $trackedFiles)) {
            $trackedFiles[] = $filePath;
            Cache::put($trackedKey, $trackedFiles, now()->addDays($this->cacheTtl));
        }
    }

    /**
     * Get default state structure.
     */
    public function getDefaultState(): array
    {
        return [
            'position' => 0,
            'size' => 0,
            'inode' => null,
            'mtime' => null,
            'last_updated' => 0,
            'file_path' => null,
        ];
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
     * Get cache key for a file.
     */
    protected function getCacheKey(string $filePath): string
    {
        // Use hash to avoid cache key issues with path characters
        return $this::CACHE_KEY_PREFIX.md5($filePath);
    }

    /**
     * Get state from file storage.
     */
    protected function getStateFromFile(string $filePath): ?array
    {
        if (! File::exists($this->stateFilePath)) {
            return null;
        }

        try {
            $content = File::get($this->stateFilePath);
            $states = json_decode($content, true);

            if (! is_array($states)) {
                return null;
            }

            $key = md5($filePath);

            return $states[$key] ?? null;
        } catch (\Exception $e) {
            Log::warning('[Paladin] Failed to read log state file', [
                'file' => $this->stateFilePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Save state to file storage.
     */
    protected function saveStateToFile(string $filePath, array $state): void
    {
        try {
            $states = [];

            // Read existing states
            if (File::exists($this->stateFilePath)) {
                $content = File::get($this->stateFilePath);
                $states = json_decode($content, true) ?: [];
            }

            // Update state for this file
            $key = md5($filePath);
            $states[$key] = $state;

            // Write back to file
            File::put($this->stateFilePath, json_encode($states, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            Log::warning('[Paladin] Failed to write log state file', [
                'file' => $this->stateFilePath,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove state from file storage.
     */
    protected function removeStateFromFile(string $filePath): void
    {
        if (! File::exists($this->stateFilePath)) {
            return;
        }

        try {
            $content = File::get($this->stateFilePath);
            $states = json_decode($content, true) ?: [];

            $key = md5($filePath);
            unset($states[$key]);

            if (empty($states)) {
                File::delete($this->stateFilePath);
            } else {
                File::put($this->stateFilePath, json_encode($states, JSON_PRETTY_PRINT));
            }
        } catch (\Exception $e) {
            Log::warning('[Paladin] Failed to remove state from file', [
                'file' => $this->stateFilePath,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
