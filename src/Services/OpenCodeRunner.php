<?php

namespace Kekser\LaravelPaladin\Services;

use Illuminate\Support\Facades\Log;
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

        // Build the OpenCode command
        // Use 'opencode run' with the prompt as an argument
        $command = sprintf(
            '%s run --dir %s %s 2>&1',
            escapeshellarg($this->binaryPath),
            escapeshellarg($workingDirectory),
            escapeshellarg($prompt)
        );

        // Execute with timeout
        $output = [];
        $returnCode = 0;
        $startTime = time();

        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Failed to start OpenCode process');
        }

        try {
            // Close stdin as we're not using it
            fclose($pipes[0]);

            // Read output with timeout
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $stdout = '';
            $stderr = '';

            while (true) {
                $status = proc_get_status($process);

                // Check timeout
                if (time() - $startTime > $this->timeout) {
                    proc_terminate($process, 9);
                    throw new RuntimeException("OpenCode execution timed out after {$this->timeout} seconds");
                }

                // Read available output
                if (! feof($pipes[1])) {
                    $stdout .= fread($pipes[1], 8192);
                }

                if (! feof($pipes[2])) {
                    $stderr .= fread($pipes[2], 8192);
                }

                // Check if process has finished
                if (! $status['running']) {
                    $returnCode = $status['exitcode'];
                    break;
                }

                usleep(100000); // 0.1 second
            }

            // Read any remaining output
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);

            $fullOutput = trim($stdout."\n".$stderr);

            Log::info('[Paladin] OpenCode execution completed', [
                'return_code' => $returnCode,
                'output_length' => strlen($fullOutput),
            ]);

            return [
                'success' => $returnCode === 0,
                'return_code' => $returnCode,
                'output' => $fullOutput,
                'stdout' => trim($stdout),
                'stderr' => trim($stderr),
            ];
        } finally {
            // Ensure pipes are closed
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                fclose($pipes[1]);
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                fclose($pipes[2]);
            }
            // Ensure process is closed
            if (is_resource($process)) {
                proc_close($process);
            }
        }
    }

    /**
     * Check if the OpenCode binary is accessible.
     */
    public function isAvailable(): bool
    {
        exec(sprintf('which %s 2>/dev/null', escapeshellarg($this->binaryPath)), $output, $returnCode);

        return $returnCode === 0;
    }
}
