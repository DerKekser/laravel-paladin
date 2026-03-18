<?php

namespace Kekser\LaravelPaladin\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

class OpenCodeRunner
{
    protected string $binaryPath;

    protected int $timeout;

    public function __construct()
    {
        $this->binaryPath = config('paladin.opencode.binary_path', 'opencode');
        $this->timeout = config('paladin.opencode.timeout', 600);
    }

    /**
     * Run OpenCode with the given prompt in the specified directory.
     */
    public function run(string $prompt, string $workingDirectory): array
    {
        if (! is_dir($workingDirectory)) {
            throw new RuntimeException("Working directory does not exist: {$workingDirectory}");
        }

        Log::info('[Paladin] Running OpenCode', [
            'working_directory' => $workingDirectory,
            'prompt_length' => strlen($prompt),
        ]);

        $result = Process::path($workingDirectory)
            ->timeout($this->timeout)
            ->run([
                $this->binaryPath,
                'run',
                '--dir',
                $workingDirectory,
                $prompt,
            ]);

        $stdout = $result->output();
        $stderr = $result->errorOutput();
        $fullOutput = trim($stdout."\n".$stderr);

        Log::info('[Paladin] OpenCode execution completed', [
            'return_code' => $result->exitCode(),
            'output_length' => strlen($fullOutput),
        ]);

        return [
            'success' => $result->successful(),
            'return_code' => $result->exitCode(),
            'output' => $fullOutput,
            'stdout' => trim($stdout),
            'stderr' => trim($stderr),
        ];
    }

    /**
     * Check if the OpenCode binary is accessible.
     */
    public function isAvailable(): bool
    {
        return Process::run(['which', $this->binaryPath])->successful();
    }
}
