<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Kekser\LaravelPaladin\Services\WorktreeSetup;

beforeEach(function () {
    $this->setup = new WorktreeSetup;
    $this->tempDir = sys_get_temp_dir().'/paladin_test_'.uniqid();
    mkdir($this->tempDir, 0777, true);
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        exec('rm -rf '.escapeshellarg($this->tempDir));
    }
});

test('it sets up worktree successfully', function () {
    Process::fake();

    File::put($this->tempDir.'/composer.json', '{}');

    config(['paladin.worktree.setup.env_source' => '.env.testing']);
    File::put(base_path('.env.testing'), 'APP_KEY=SomeKey');

    $result = $this->setup->setup($this->tempDir);

    expect($result)->toBeTrue();
    expect($this->tempDir.'/.env')->toBeFile();
    expect($this->tempDir.'/storage/logs')->toBeDirectory();

    Process::assertRan(function ($process) {
        return str_contains($process->command, 'composer install');
    });

    unlink(base_path('.env.testing'));
});

test('it handles missing composer json', function () {
    Process::fake();

    $result = $this->setup->setup($this->tempDir);

    expect($result)->toBeFalse();
});

test('it runs custom commands', function () {
    Process::fake();

    config(['paladin.worktree.setup.custom_commands' => ['php artisan migrate']]);
    File::put($this->tempDir.'/composer.json', '{}');

    $result = $this->setup->setup($this->tempDir);

    expect($result)->toBeTrue();
    Process::assertRan(function ($process) {
        return str_contains($process->command, 'artisan migrate');
    });
});
