<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Kekser\LaravelPaladin\Services\GitService;

beforeEach(function () {
    Process::preventStrayProcesses();
    $this->gitService = new GitService;
    $this->tempDir = sys_get_temp_dir().'/paladin_test_'.uniqid();
    mkdir($this->tempDir, 0777, true);
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        exec('rm -rf '.escapeshellarg($this->tempDir));
    }
});

test('it checks if remote exists', function () {
    Process::fake([
        '*' => Process::result('', 0),
    ]);

    $result = $this->gitService->hasRemote($this->tempDir);

    expect($result)->toBeTrue();
    Process::assertRan(function ($process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return str_contains($command, 'git') &&
               str_contains($command, 'remote') &&
               $process->path === $this->tempDir;
    });
});

test('it returns false when remote does not exist', function () {
    Process::fake([
        '*' => Process::result('', '', 1),
    ]);

    $result = (new GitService)->hasRemote($this->tempDir);

    expect($result)->toBeFalse();
});

test('it creates a branch', function () {
    Process::fake([
        '*' => Process::result('', 0),
    ]);

    $result = $this->gitService->createBranch($this->tempDir, 'feature-branch');

    expect($result)->toBeTrue();
    Process::assertRan(function ($process) {
        $command = is_array($process->command) ? implode(' ', $process->command) : $process->command;

        return str_contains($command, 'git') &&
               str_contains($command, 'checkout') &&
               str_contains($command, 'feature-branch');
    });
});

test('it fails to create a branch', function () {
    Process::fake([
        '*' => Process::result('', 'Error', 1),
    ]);

    Log::shouldReceive('error')->once();

    $result = (new GitService)->createBranch($this->tempDir, 'feature-branch');

    expect($result)->toBeFalse();
});

test('it commits changes', function () {
    Process::fake([
        '*' => Process::result('', 0),
    ]);

    $result = $this->gitService->commit($this->tempDir, 'Fix issue');

    expect($result)->toBeTrue();
});

test('it fails to commit changes', function () {
    Process::fake([
        '*' => Process::result('', 'Commit error', 1),
    ]);

    Log::shouldReceive('error')->once();

    $result = $this->gitService->commit($this->tempDir, 'Fix issue');

    expect($result)->toBeFalse();
});

test('it pushes a branch', function () {
    Process::fake([
        '*' => Process::result('', 0),
    ]);

    $result = $this->gitService->push($this->tempDir, 'feature-branch');

    expect($result)->toBeTrue();
});

test('it fails to push branch', function () {
    Process::fake([
        '*' => Process::result('', 'Push error', 1),
    ]);

    Log::shouldReceive('error')->once();

    $result = $this->gitService->push($this->tempDir, 'feature-branch');

    expect($result)->toBeFalse();
});
