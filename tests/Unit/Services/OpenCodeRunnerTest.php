<?php

use Kekser\LaravelPaladin\Services\OpenCodeRunner;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::preventStrayProcesses();
    $this->tempDir = sys_get_temp_dir() . '/paladin_test_' . uniqid();
    mkdir($this->tempDir, 0777, true);
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        exec('rm -rf ' . escapeshellarg($this->tempDir));
    }
});

test('it runs opencode successfully', function () {
    Process::fake([
        '*' => Process::result('Success output', 0),
    ]);

    $runner = new OpenCodeRunner();
    $result = $runner->run('Fix this bug', $this->tempDir);

    expect($result['success'])->toBeTrue();
    expect($result['return_code'])->toBe(0);
    expect($result['output'])->toContain('Success output');
});

test('it handles opencode failure', function () {
    Process::fake([
        '*' => Process::result('', 'Error output', 1),
    ]);

    $runner = new OpenCodeRunner();
    $result = $runner->run('Fix this bug', $this->tempDir);

    expect($result['success'])->toBeFalse();
    expect($result['return_code'])->toBe(1);
    expect($result['output'])->toContain('Error output');
});

test('it throws exception if working directory does not exist', function () {
    $runner = new OpenCodeRunner();
    $runner->run('Fix this bug', '/non-existent-directory');
})->throws(RuntimeException::class, 'Working directory does not exist');

test('it checks if opencode is available', function () {
    Process::fake([
        '*' => Process::result('/usr/bin/opencode', 0),
    ]);

    $runner = new OpenCodeRunner();
    expect($runner->isAvailable())->toBeTrue();
});
