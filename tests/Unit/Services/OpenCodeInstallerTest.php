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

test('it ensures installed by installing if not already present', function () {
    Http::fake([
        'https://opencode.ai/install' => Http::response('echo "install script"', 200),
    ]);

    Process::fake([
        'bash *' => Process::result('Success', 0),
        '*' => Process::sequence()
            ->push(Process::result('', '', 1))
            ->push(Process::result('/usr/bin/opencode', 0)),
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

test('it returns null version if not installed', function () {
    $installer = new OpenCodeInstaller();

    config(['paladin.opencode.binary_path' => 'nonexistent-binary']);
    Process::fake([
        'which nonexistent-binary' => Process::result('', '', 1),
        '*' => Process::result('', '', 1),
    ]);

    expect($installer->getVersion())->toBeNull();
});

test('it returns null version if version command fails', function () {
    Process::fake([
        'which opencode' => Process::result('/usr/bin/opencode', 0),
        'opencode --version' => Process::result('', 'Error', 1),
        '*' => Process::result('', '', 1),
    ]);

    $installer = new OpenCodeInstaller();
    expect($installer->getVersion())->toBeNull();
});

test('it throws exception when install script download fails', function () {
    Http::fake([
        'https://opencode.ai/install' => Http::response('Not Found', 404),
    ]);

    Process::fake([
        'which opencode' => Process::result('', '', 1),
        '*' => Process::result('', '', 1),
    ]);

    $installer = new OpenCodeInstaller();
    $installer->install();
})->throws(RuntimeException::class, 'Failed to download OpenCode installation script');

test('it throws exception when installation script fails', function () {
    Http::fake([
        'https://opencode.ai/install' => Http::response('echo "install script"', 200),
    ]);

    Process::fake([
        'which opencode' => Process::result('', '', 1),
        'bash *' => Process::result('', 'Install error', 1),
        '*' => Process::result('', '', 1),
    ]);

    $installer = new OpenCodeInstaller();
    $installer->install();
})->throws(RuntimeException::class, 'OpenCode installation failed');

test('it throws exception when binary not found after installation', function () {
    Http::fake([
        'https://opencode.ai/install' => Http::response('echo "install script"', 200),
    ]);

    Process::fake([
        'which opencode' => Process::result('', '', 1),
        'bash *' => Process::result('Success', 0),
        '*' => Process::result('', '', 1),
    ]);

    $installer = new OpenCodeInstaller();
    $installer->install();
})->throws(RuntimeException::class, 'OpenCode installation completed but binary not found in PATH');
