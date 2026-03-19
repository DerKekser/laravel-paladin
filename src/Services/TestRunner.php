<?php

namespace Kekser\LaravelPaladin\Services;

use Illuminate\Support\Facades\Log;
use RuntimeException;

class TestRunner
{
    protected string $testCommand;

    protected int $timeout;

    public function __construct()
    {
        $this->testCommand = config('paladin.testing.command', 'php artisan test');
        $this->timeout = config('paladin.testing.timeout', 300);
    }

    /**
     * Run tests in the specified directory.
     */
    public function run(string $workingDirectory): array
    {
        if (! is_dir($workingDirectory)) {
            throw new RuntimeException("Working directory does not exist: {$workingDirectory}");
        }

        Log::info('[Paladin] Running tests', [
            'working_directory' => $workingDirectory,
            'command' => $this->testCommand,
        ]);

        $startTime = time();

        $command = sprintf(
            'cd %s && %s 2>&1',
            escapeshellarg($workingDirectory),
            $this->testCommand
        );

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
            throw new RuntimeException('Failed to start test process');
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';

        while (true) {
            $status = proc_get_status($process);

            // Check timeout
            if (time() - $startTime > $this->timeout) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return [
                    'passed' => false,
                    'timed_out' => true,
                    'output' => "Tests timed out after {$this->timeout} seconds",
                ];
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
                break;
            }

            usleep(100000); // 0.1 second
        }

        // Read any remaining output
        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);

        $fullOutput = trim($stdout."\n".$stderr);

        $passed = $returnCode === 0;

        Log::info('[Paladin] Test execution completed', [
            'passed' => $passed,
            'return_code' => $returnCode,
        ]);

        return [
            'passed' => $passed,
            'timed_out' => false,
            'return_code' => $returnCode,
            'output' => $fullOutput,
            'failed_tests' => $this->extractFailedTests($fullOutput),
        ];
    }

    /**
     * Extract information about failed tests from the output.
     */
    protected function extractFailedTests(string $output): array
    {
        $failed = [];

        // Try to parse PHPUnit output format
        $lines = explode("\n", $output);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Simple match for anything that looks like a test name after some status
            if (preg_match('/^(FAILED|ERRORED?|FAIL|✗|⨯|✘)\s+((Tests|App).+)$/iu', $line, $matches)) {
                $failed[] = [
                    'status' => trim($matches[1]),
                    'test' => trim($matches[2]),
                ];
            } elseif (preg_match('/^\d+\)\s+(.+)$/', $line, $matches)) {
                $failed[] = [
                    'status' => 'FAILED',
                    'test' => trim($matches[1]),
                ];
            }

            // Alternative format: Match failure summaries
            if (preg_match('/(\d+)\s+failed/', $line, $matches)) {
                $failed['summary'] = $matches[0];
            }
        }

        return $failed;
    }

    /**
     * Set a custom test command.
     */
    public function setCommand(string $command): self
    {
        $this->testCommand = $command;

        return $this;
    }
}
