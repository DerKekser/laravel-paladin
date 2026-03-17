<?php

use Kekser\LaravelPaladin\Services\OpenCodeInstaller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::preventStrayProcesses();
});

test('it checks if installed', function () {
    Process::fake([
        '*' => Process::result('/usr/bin/opencode', 0),
    ]);

    $installer = new OpenCodeInstaller();
    expect($installer->isInstalled())->toBeTrue();
});

test('it checks if not installed', function () {
    Process::fake([
        '*' => Process::result('', '', 1),
    ]);

    $installer = new OpenCodeInstaller();
    expect($installer->isInstalled())->toBeFalse();
});

test('it ensures installed when already installed', function () {
    Process::fake([
        '*' => Process::result('/usr/bin/opencode', 0),
    ]);

    $installer = new OpenCodeInstaller();
    expect($installer->ensureInstalled())->toBeTrue();
});

test('it throws exception if not installed and auto install disabled', function () {
    config(['paladin.opencode.auto_install' => false]);
    Process::fake([
        '*' => Process::result('', '', 1),
    ]);

    $installer = new OpenCodeInstaller();
    $installer->ensureInstalled();
})->throws(RuntimeException::class, 'OpenCode is not installed and auto-installation is disabled');

test('it installs successfully', function () {
    Http::fake([
        'https://opencode.ai/install' => Http::response('echo "install script"', 200),
    ]);

    Process::fake([
        'bash *' => Process::result('Installed successfully', 0),
        'which *' => Process::result('/usr/bin/opencode', 0),
        '*' => Process::result('/usr/bin/opencode', 0),
    ]);

    $installer = new OpenCodeInstaller();
    expect($installer->install())->toBeTrue();
});

test('it gets version', function () {
    Process::fake([
        '*' => Process::result('OpenCode version 1.0.0', 0),
    ]);

    $installer = new OpenCodeInstaller();
    expect($installer->getVersion())->toBe('OpenCode version 1.0.0');
});
