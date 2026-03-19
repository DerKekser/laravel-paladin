<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Kekser\LaravelPaladin\Services\LogStateStorage;

beforeEach(function () {
    // Use a temp storage path for testing
    Storage::fake('local');
    config(['paladin.log.state_cache_ttl' => 30]);

    // Create temp directory for state file
    $this->tempDir = sys_get_temp_dir().'/log-state-test-'.uniqid();
    mkdir($this->tempDir, 0755, true);

    // Create the LogStateStorage with reflection to set the state file path
    $this->stateStorage = new class($this->tempDir) extends LogStateStorage
    {
        private string $testDir;

        public function __construct(string $testDir)
        {
            $this->testDir = $testDir;
            parent::__construct();
        }

        protected function getFileStats(string $filePath): array
        {
            $stat = stat($filePath);

            return [
                'size' => $stat['size'] ?? 0,
                'inode' => $stat['ino'] ?? null,
                'mtime' => $stat['mtime'] ?? null,
            ];
        }
    };

    // Use reflection to override the state file path
    $reflection = new ReflectionClass($this->stateStorage);
    $property = $reflection->getProperty('stateFilePath');
    $property->setAccessible(true);
    $property->setValue($this->stateStorage, $this->tempDir.'/paladin-log-state.json');
});

afterEach(function () {
    // Cleanup
    if (is_dir($this->tempDir)) {
        $files = glob($this->tempDir.'/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($this->tempDir);
    }

    // Clear cache
    Cache::flush();
});

function createTestLogFile(string $dir, string $name, string $content = ''): string
{
    $path = $dir.'/'.$name;
    file_put_contents($path, $content);

    return $path;
}

test('it returns default state when no state exists', function () {
    $filePath = createTestLogFile($this->tempDir, 'test.log');

    $state = $this->stateStorage->getState($filePath);

    expect($state)->toBe([
        'position' => 0,
        'size' => 0,
        'inode' => null,
        'mtime' => null,
        'last_updated' => 0,
        'file_path' => null,
    ]);
});

test('it saves state to cache and file', function () {
    $filePath = createTestLogFile($this->tempDir, 'test.log', 'test content');
    $state = [
        'position' => 100,
        'size' => 200,
        'inode' => 12345,
        'mtime' => time(),
    ];

    $this->stateStorage->saveState($filePath, $state);

    // Check cache
    $cacheKey = 'paladin.log_state.'.md5($filePath);
    expect(Cache::has($cacheKey))->toBeTrue();
    expect(Cache::get($cacheKey)['position'])->toBe(100);

    // Check file
    $stateFilePath = $this->tempDir.'/paladin-log-state.json';
    expect(file_exists($stateFilePath))->toBeTrue();
});

test('it retrieves state from cache when available', function () {
    $filePath = createTestLogFile($this->tempDir, 'test.log', 'test content');
    $cacheKey = 'paladin.log_state.'.md5($filePath);

    // Set state in cache
    Cache::put($cacheKey, [
        'position' => 150,
        'size' => 300,
        'inode' => 99999,
    ], now()->addDays(30));

    $state = $this->stateStorage->getState($filePath);

    expect($state['position'])->toBe(150);
    expect($state['size'])->toBe(300);
    expect($state['inode'])->toBe(99999);
});

test('it falls back to file storage when cache is empty', function () {
    $filePath = createTestLogFile($this->tempDir, 'test.log', 'test content');

    // Save state first
    $this->stateStorage->saveState($filePath, [
        'position' => 75,
        'size' => 150,
        'inode' => 54321,
    ]);

    // Clear cache
    $cacheKey = 'paladin.log_state.'.md5($filePath);
    Cache::forget($cacheKey);

    // Should still get state from file
    $state = $this->stateStorage->getState($filePath);

    expect($state['position'])->toBe(75);
    expect($state['size'])->toBe(150);
    expect($state['inode'])->toBe(54321);
});

test('it detects file rotation when file does not exist', function () {
    $nonExistentFile = $this->tempDir.'/nonexistent.log';
    $state = [
        'inode' => 12345,
        'size' => 100,
        'mtime' => time() - 3600,
    ];

    $hasRotated = $this->stateStorage->hasFileRotated($nonExistentFile, $state);

    expect($hasRotated)->toBeTrue();
});

test('it detects file rotation when inode changes', function () {
    $filePath = createTestLogFile($this->tempDir, 'test.log', 'test content');
    $oldInode = fileinode($filePath);

    $state = [
        'inode' => 999999, // Different inode
        'size' => 100,
        'mtime' => time() - 3600,
    ];

    $hasRotated = $this->stateStorage->hasFileRotated($filePath, $state);

    expect($hasRotated)->toBeTrue();
});

test('it detects file rotation when file size decreases', function () {
    // Create file with content
    $filePath = createTestLogFile($this->tempDir, 'test.log', 'original content that is longer');
    $originalSize = filesize($filePath);

    $state = [
        'inode' => fileinode($filePath),
        'size' => $originalSize + 1000, // Larger than current
        'mtime' => time() - 3600,
    ];

    $hasRotated = $this->stateStorage->hasFileRotated($filePath, $state);

    expect($hasRotated)->toBeTrue();
});

test('it detects file rotation when mtime is older than expected', function () {
    $filePath = createTestLogFile($this->tempDir, 'test.log', 'content');
    $currentMtime = filemtime($filePath);

    // State has a future mtime (older file was replaced)
    $state = [
        'inode' => fileinode($filePath),
        'size' => filesize($filePath),
        'mtime' => $currentMtime + 1000,
    ];

    $hasRotated = $this->stateStorage->hasFileRotated($filePath, $state);

    expect($hasRotated)->toBeTrue();
});

test('it does not detect rotation when file is unchanged', function () {
    $filePath = createTestLogFile($this->tempDir, 'test.log', 'content');
    clearstatcache(true, $filePath);

    $state = [
        'inode' => fileinode($filePath),
        'size' => filesize($filePath),
        'mtime' => filemtime($filePath),
    ];

    $hasRotated = $this->stateStorage->hasFileRotated($filePath, $state);

    expect($hasRotated)->toBeFalse();
});

test('it resets state for a specific file', function () {
    $filePath = createTestLogFile($this->tempDir, 'test.log', 'content');

    // Save state
    $this->stateStorage->saveState($filePath, [
        'position' => 100,
        'size' => 200,
    ]);

    // Reset state
    $this->stateStorage->resetState($filePath);

    // Should return default state
    $state = $this->stateStorage->getState($filePath);
    expect($state['position'])->toBe(0);
    expect($state['size'])->toBe(0);

    // Cache should be cleared
    $cacheKey = 'paladin.log_state.'.md5($filePath);
    expect(Cache::has($cacheKey))->toBeFalse();
});

test('it resets all states', function () {
    $file1 = createTestLogFile($this->tempDir, 'test1.log', 'content');
    $file2 = createTestLogFile($this->tempDir, 'test2.log', 'content');

    // Save states
    $this->stateStorage->saveState($file1, ['position' => 100]);
    $this->stateStorage->saveState($file2, ['position' => 200]);

    // Reset all
    $this->stateStorage->resetAllStates();

    // Both should return default
    expect($this->stateStorage->getState($file1)['position'])->toBe(0);
    expect($this->stateStorage->getState($file2)['position'])->toBe(0);

    // State file should be deleted
    expect(file_exists($this->tempDir.'/paladin-log-state.json'))->toBeFalse();
});

test('it handles invalid json in state file gracefully', function () {
    $filePath = createTestLogFile($this->tempDir, 'test.log', 'content');

    // Create invalid JSON in state file
    file_put_contents($this->tempDir.'/paladin-log-state.json', 'not valid json{');

    // Should return default state
    $state = $this->stateStorage->getState($filePath);
    expect($state['position'])->toBe(0);
});

test('it handles non-array json in state file', function () {
    $filePath = createTestLogFile($this->tempDir, 'test.log', 'content');

    // Create non-array JSON
    file_put_contents($this->tempDir.'/paladin-log-state.json', '"just a string"');

    // Should return default state
    $state = $this->stateStorage->getState($filePath);
    expect($state['position'])->toBe(0);
});

test('it handles file read errors gracefully', function () {
    $filePath = createTestLogFile($this->tempDir, 'test.log', 'content');

    // Create state file with valid JSON
    file_put_contents($this->tempDir.'/paladin-log-state.json', json_encode([
        md5($filePath) => ['position' => 100],
    ]));

    // Make file unreadable (simulate permission error)
    chmod($this->tempDir.'/paladin-log-state.json', 0000);

    // Should return default state
    $state = $this->stateStorage->getState($filePath);
    expect($state['position'])->toBe(0);

    // Cleanup
    chmod($this->tempDir.'/paladin-log-state.json', 0644);
});

test('it handles file write errors gracefully', function () {
    $filePath = createTestLogFile($this->tempDir, 'test.log', 'content');

    // Make directory unwritable
    chmod($this->tempDir, 0555);

    // Should not throw exception
    $this->stateStorage->saveState($filePath, ['position' => 100]);

    // Still should save to cache
    $cacheKey = 'paladin.log_state.'.md5($filePath);
    expect(Cache::has($cacheKey))->toBeTrue();

    // Cleanup
    chmod($this->tempDir, 0755);
});

test('it removes empty state file when removing last state', function () {
    $filePath = createTestLogFile($this->tempDir, 'test.log', 'content');

    // Save state
    $this->stateStorage->saveState($filePath, ['position' => 100]);

    // Verify file exists
    expect(file_exists($this->tempDir.'/paladin-log-state.json'))->toBeTrue();

    // Reset state
    $this->stateStorage->resetState($filePath);

    // File should be deleted since no states remain
    expect(file_exists($this->tempDir.'/paladin-log-state.json'))->toBeFalse();
});

test('it updates existing state in file without removing others', function () {
    $file1 = createTestLogFile($this->tempDir, 'test1.log', 'content1');
    $file2 = createTestLogFile($this->tempDir, 'test2.log', 'content2');

    // Save both states
    $this->stateStorage->saveState($file1, ['position' => 100]);
    $this->stateStorage->saveState($file2, ['position' => 200]);

    // Update first state
    $this->stateStorage->saveState($file1, ['position' => 150]);

    // Reset first state
    $this->stateStorage->resetState($file1);

    // Second state should still exist
    $state = $this->stateStorage->getState($file2);
    expect($state['position'])->toBe(200);
});

test('it handles state file removal with multiple files', function () {
    $file1 = createTestLogFile($this->tempDir, 'test1.log', 'content1');
    $file2 = createTestLogFile($this->tempDir, 'test2.log', 'content2');

    // Save both states
    $this->stateStorage->saveState($file1, ['position' => 100]);
    $this->stateStorage->saveState($file2, ['position' => 200]);

    // Reset only one
    $this->stateStorage->resetState($file1);

    // File should still exist with second state
    expect(file_exists($this->tempDir.'/paladin-log-state.json'))->toBeTrue();

    // Second state should be preserved
    $content = file_get_contents($this->tempDir.'/paladin-log-state.json');
    $states = json_decode($content, true);
    expect($states)->toHaveKey(md5($file2));
    expect($states)->not->toHaveKey(md5($file1));
});

test('it tracks files for reset all', function () {
    $file1 = createTestLogFile($this->tempDir, 'test1.log', 'content');
    $file2 = createTestLogFile($this->tempDir, 'test2.log', 'content');

    // Save states
    $this->stateStorage->saveState($file1, ['position' => 100]);
    $this->stateStorage->saveState($file2, ['position' => 200]);

    // Check tracked files
    $trackedKey = 'paladin.log_state._tracked_files';
    $trackedFiles = Cache::get($trackedKey, []);

    expect($trackedFiles)->toContain($file1);
    expect($trackedFiles)->toContain($file2);
});

test('it does not duplicate tracked files', function () {
    $filePath = createTestLogFile($this->tempDir, 'test.log', 'content');

    // Save state multiple times
    $this->stateStorage->saveState($filePath, ['position' => 100]);
    $this->stateStorage->saveState($filePath, ['position' => 200]);
    $this->stateStorage->saveState($filePath, ['position' => 300]);

    // Check tracked files - should only appear once
    $trackedKey = 'paladin.log_state._tracked_files';
    $trackedFiles = Cache::get($trackedKey, []);

    $count = 0;
    foreach ($trackedFiles as $file) {
        if ($file === $filePath) {
            $count++;
        }
    }
    expect($count)->toBe(1);
});

test('it handles exception when file does not exist during rotation check', function () {
    $nonExistentFile = '/non/existent/path/test.log';

    $state = [
        'inode' => 12345,
        'size' => 100,
        'mtime' => time() - 3600,
    ];

    $hasRotated = $this->stateStorage->hasFileRotated($nonExistentFile, $state);

    expect($hasRotated)->toBeTrue();
});
