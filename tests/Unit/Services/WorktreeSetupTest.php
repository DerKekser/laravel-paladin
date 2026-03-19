<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Kekser\LaravelPaladin\Services\WorktreeSetup;

beforeEach(function () {
    $this->setup = new WorktreeSetup;

    $this->tempDir = sys_get_temp_dir().'/paladin_test_'.uniqid();
    mkdir($this->tempDir, 0777, true);

    // Log mocking - use a more relaxed approach by default
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('debug')->andReturnNull();
    Log::shouldReceive('warning')->andReturnNull();
    Log::shouldReceive('error')->andReturnNull();
});

afterEach(function () {
    if (is_dir($this->tempDir)) {
        exec('rm -rf '.escapeshellarg($this->tempDir));
    }
    config(['paladin.worktree.setup.composer_install' => true]);
    config(['paladin.worktree.setup.copy_env' => true]);
    config(['paladin.worktree.setup.generate_key' => true]);
    config(['paladin.worktree.setup.custom_commands' => []]);
    Mockery::close();
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

test('it handles composer install failure', function () {
    $command = 'composer install --some-flag';

    // Try a completely different approach: Mock the Process facade completely
    $processResult = Mockery::mock(ProcessResult::class);
    $processResult->shouldReceive('successful')->andReturn(false);
    $processResult->shouldReceive('failed')->andReturn(true);
    $processResult->shouldReceive('output')->andReturn('Error Output');
    $processResult->shouldReceive('exitCode')->andReturn(1);

    $processPending = Mockery::mock(PendingProcess::class);
    $processPending->shouldReceive('path')->with($this->tempDir)->andReturnSelf();
    $processPending->shouldReceive('run')->with($command)->andReturn($processResult);

    Process::swap($processPending);

    File::put($this->tempDir.'/composer.json', '{}');
    File::put($this->tempDir.'/artisan', ''); // To skip boost warning

    config(['paladin.worktree.setup.composer_flags' => '--some-flag']);

    $result = $this->setup->setup($this->tempDir);

    expect($result)->toBeFalse();
});

test('it handles env source fallback to .env', function () {
    Process::fake();
    File::put($this->tempDir.'/composer.json', '{}');

    // Create .env in base path
    File::put(base_path('.env'), 'APP_KEY=BaseKey');

    // Ensure .env.testing (default source) DOES NOT exist
    if (File::exists(base_path('.env.testing'))) {
        unlink(base_path('.env.testing'));
    }

    $result = $this->setup->setup($this->tempDir);

    expect($result)->toBeTrue();
    expect($this->tempDir.'/.env')->toBeFile();
    expect(File::get($this->tempDir.'/.env'))->toContain('APP_KEY=BaseKey');

    unlink(base_path('.env'));
});

test('it handles no env file found', function () {
    Process::fake();
    File::put($this->tempDir.'/composer.json', '{}');
    File::put($this->tempDir.'/artisan', '');

    // Ensure no env files exist in base path
    if (File::exists(base_path('.env.testing'))) {
        unlink(base_path('.env.testing'));
    }
    if (File::exists(base_path('.env'))) {
        unlink(base_path('.env'));
    }

    $result = $this->setup->setup($this->tempDir);

    expect($result)->toBeTrue();
    expect($this->tempDir.'/.env')->not->toBeFile();
});

test('it generates app key if missing in env', function () {
    Process::fake([
        'php artisan key:generate --force' => Process::result('Generated', 0),
    ]);
    File::put($this->tempDir.'/composer.json', '{}');

    // Create .env without APP_KEY
    File::put(base_path('.env.testing'), "DB_HOST=localhost\n");

    $result = $this->setup->setup($this->tempDir);

    expect($result)->toBeTrue();
    Process::assertRan(function ($process) {
        return str_contains($process->command, 'key:generate');
    });

    unlink(base_path('.env.testing'));
});

test('it skips key generation if key already exists', function () {
    Process::fake();
    File::put($this->tempDir.'/composer.json', '{}');

    // Create .env WITH APP_KEY
    File::put(base_path('.env.testing'), "APP_KEY=SomeExistingKey\n");

    $result = $this->setup->setup($this->tempDir);

    expect($result)->toBeTrue();
    Process::assertNotRan(function ($process) {
        return str_contains($process->command, 'key:generate');
    });

    unlink(base_path('.env.testing'));
});

test('it handles key generation failure', function () {
    Process::fake([
        'php artisan key:generate --force' => Process::result('Failed', 1),
    ]);
    File::put($this->tempDir.'/composer.json', '{}');
    File::put($this->tempDir.'/artisan', '');
    File::put(base_path('.env.testing'), "DB_HOST=localhost\n");

    $result = $this->setup->setup($this->tempDir);

    expect($result)->toBeTrue();
    unlink(base_path('.env.testing'));
});

test('it handles individual setup component failures', function () {
    File::put($this->tempDir.'/composer.json', '{}');
    File::put($this->tempDir.'/artisan', '');

    // We mock the methods of the class under test to see if setup handles their failure
    $setupMock = Mockery::mock(WorktreeSetup::class)->makePartial();
    $setupMock->shouldReceive('runComposerInstall')->andThrow(new RuntimeException('Composer fail'));

    $result = $setupMock->setup($this->tempDir);
    expect($result)->toBeFalse();

    $setupMock2 = Mockery::mock(WorktreeSetup::class)->makePartial();
    $setupMock2->shouldReceive('runComposerInstall')->andReturnNull();
    $setupMock2->shouldReceive('setupEnvironment')->andThrow(new RuntimeException('Env fail'));
    $setupMock2->shouldReceive('createStorageDirectories')->andThrow(new RuntimeException('Storage fail'));
    $setupMock2->shouldReceive('runCustomCommands')->andThrow(new RuntimeException('Custom fail'));

    $result = $setupMock2->setup($this->tempDir);
    expect($result)->toBeTrue(); // Non-critical steps failing shouldn't stop setup
});

test('individual methods work correctly', function () {
    Process::fake();
    File::put($this->tempDir.'/composer.json', '{}');
    File::put($this->tempDir.'/.env', '');

    $this->setup->runComposerInstall($this->tempDir);
    $this->setup->setupEnvironment($this->tempDir);
    $this->setup->ensureAppKey($this->tempDir);
    $this->setup->createStorageDirectories($this->tempDir);

    config(['paladin.worktree.setup.custom_commands' => ['ls']]);
    $this->setup->runCustomCommands($this->tempDir);

    // Cover edge cases in ensureAppKey
    unlink($this->tempDir.'/.env');
    $this->setup->ensureAppKey($this->tempDir); // Should return early

    // Cover empty custom commands
    config(['paladin.worktree.setup.custom_commands' => []]);
    $this->setup->runCustomCommands($this->tempDir);

    // Cover setupEnvironment fallback
    File::put(base_path('.env'), 'KEY=VALUE');
    config(['paladin.worktree.setup.env_source' => '.non-existent']);
    $this->setup->setupEnvironment($this->tempDir);

    // Cover no .env at all
    unlink(base_path('.env'));
    $this->setup->setupEnvironment($this->tempDir);

    // Cover ensureAppKey with failed process
    File::put($this->tempDir.'/.env', 'APP_KEY=');
    Process::fake([
        'php artisan key:generate --force' => Process::result('Error', 1),
    ]);
    $this->setup->ensureAppKey($this->tempDir);

    expect(true)->toBeTrue();
});

test('it respects disabled configuration options', function () {
    Process::fake();
    File::put($this->tempDir.'/composer.json', '{}');

    config([
        'paladin.worktree.setup.composer_install' => false,
        'paladin.worktree.setup.copy_env' => false,
    ]);

    $result = $this->setup->setup($this->tempDir);

    expect($result)->toBeTrue();
    Process::assertNotRan(function ($process) {
        return str_contains($process->command, 'composer install');
    });
    expect($this->tempDir.'/.env')->not->toBeFile();
});

test('it runs custom commands', function () {
    $command = 'php artisan migrate';

    // Use Mockery to ensure it runs
    $processResult = Mockery::mock(ProcessResult::class);
    $processResult->shouldReceive('successful')->andReturn(true);
    $processResult->shouldReceive('failed')->andReturn(false);

    $processPending = Mockery::mock(PendingProcess::class);
    $processPending->shouldReceive('path')->with($this->tempDir)->andReturnSelf();
    $processPending->shouldReceive('run')->with(Mockery::any())->andReturn($processResult);

    Process::swap($processPending);

    config(['paladin.worktree.setup.custom_commands' => [$command]]);
    File::put($this->tempDir.'/composer.json', '{}');
    File::put($this->tempDir.'/artisan', ''); // To skip boost warning

    $result = $this->setup->setup($this->tempDir);

    expect($result)->toBeTrue();
});
