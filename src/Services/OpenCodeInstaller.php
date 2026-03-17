<?php

namespace Kekser\LaravelPaladin\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenCodeInstaller
{
    protected string $installScriptUrl = 'https://opencode.ai/install';

    /**
     * Check if OpenCode is installed.
     */
    public function isInstalled(): bool
    {
        $binaryPath = config('paladin.opencode.binary_path', 'opencode');

        exec("which {$binaryPath} 2>/dev/null", $output, $returnCode);

        return $returnCode === 0 && ! empty($output);
    }

    /**
     * Install OpenCode if not already installed.
     */
    public function ensureInstalled(): bool
    {
        if ($this->isInstalled()) {
            return true;
        }

        if (! config('paladin.opencode.auto_install', true)) {
            throw new RuntimeException(
                'OpenCode is not installed and auto-installation is disabled. '.
                'Please install OpenCode manually from https://opencode.ai or enable auto-installation in config.'
            );
        }

        Log::info('[Paladin] OpenCode not found, attempting to install...');

        return $this->install();
    }

    /**
     * Install OpenCode by downloading and running the install script.
     */
    public function install(): bool
    {
        try {
            // Download the installation script
            $response = Http::timeout(30)->get($this->installScriptUrl);

            if (! $response->successful()) {
                throw new RuntimeException('Failed to download OpenCode installation script');
            }

            $script = $response->body();

            // Create temporary file for the script
            $tempFile = tempnam(sys_get_temp_dir(), 'opencode_install_');
            file_put_contents($tempFile, $script);
            chmod($tempFile, 0755);

            // Execute the installation script
            exec("bash {$tempFile} 2>&1", $output, $returnCode);

            // Clean up temporary file
            @unlink($tempFile);

            if ($returnCode !== 0) {
                Log::error('[Paladin] OpenCode installation failed', [
                    'output' => implode("\n", $output),
                    'return_code' => $returnCode,
                ]);

                throw new RuntimeException(
                    'OpenCode installation failed: '.implode("\n", $output)
                );
            }

            // Verify installation
            if (! $this->isInstalled()) {
                throw new RuntimeException('OpenCode installation completed but binary not found in PATH');
            }

            Log::info('[Paladin] OpenCode installed successfully');

            return true;
        } catch (\Exception $e) {
            Log::error('[Paladin] OpenCode installation error', [
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get the OpenCode version if installed.
     */
    public function getVersion(): ?string
    {
        if (! $this->isInstalled()) {
            return null;
        }

        $binaryPath = config('paladin.opencode.binary_path', 'opencode');

        exec("{$binaryPath} --version 2>&1", $output, $returnCode);

        if ($returnCode === 0 && ! empty($output)) {
            return trim($output[0]);
        }

        return null;
    }
}
