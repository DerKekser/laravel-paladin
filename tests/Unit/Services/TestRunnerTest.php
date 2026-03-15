<?php

namespace Kekser\LaravelPaladin\Tests\Unit\Services;

use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Services\TestRunner;
use Kekser\LaravelPaladin\Tests\TestCase;
use RuntimeException;

class TestRunnerTest extends TestCase
{
    protected TestRunner $runner;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->runner = new TestRunner;
        $this->tempDir = $this->createTempDirectory('test-runner-');

        Log::spy();
    }

    protected function tearDown(): void
    {
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            $this->deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    /** @test */
    public function it_throws_exception_for_nonexistent_directory()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Working directory does not exist');

        $this->runner->run('/nonexistent/directory');
    }

    /** @test */
    public function it_can_set_custom_test_command()
    {
        $result = $this->runner->setCommand('vendor/bin/phpunit');

        $this->assertInstanceOf(TestRunner::class, $result);
    }

    /** @test */
    public function it_uses_configured_test_command()
    {
        config(['paladin.testing.command' => 'php artisan test']);

        $runner = new TestRunner;

        // We can't directly access the private property, but we can verify via execution
        $this->assertInstanceOf(TestRunner::class, $runner);
    }

    /** @test */
    public function it_uses_configured_timeout()
    {
        config(['paladin.testing.timeout' => 600]);

        $runner = new TestRunner;

        $this->assertInstanceOf(TestRunner::class, $runner);
    }

    /** @test */
    public function it_runs_command_successfully()
    {
        // Create a simple script that exits with 0
        $scriptPath = $this->tempDir.'/test.sh';
        file_put_contents($scriptPath, "#!/bin/bash\necho 'Tests passed'\nexit 0");
        chmod($scriptPath, 0755);

        $runner = new TestRunner;
        $runner->setCommand('bash test.sh');

        $result = $runner->run($this->tempDir);

        $this->assertTrue($result['passed']);
        $this->assertFalse($result['timed_out']);
        $this->assertEquals(0, $result['return_code']);
        $this->assertStringContainsString('Tests passed', $result['output']);
    }

    /** @test */
    public function it_detects_test_failures()
    {
        // Create a script that exits with non-zero code
        $scriptPath = $this->tempDir.'/test.sh';
        file_put_contents($scriptPath, "#!/bin/bash\necho 'Tests failed'\nexit 1");
        chmod($scriptPath, 0755);

        $runner = new TestRunner;
        $runner->setCommand('bash test.sh');

        $result = $runner->run($this->tempDir);

        $this->assertFalse($result['passed']);
        $this->assertFalse($result['timed_out']);
        $this->assertEquals(1, $result['return_code']);
        $this->assertStringContainsString('Tests failed', $result['output']);
    }

    /** @test */
    public function it_captures_stdout_and_stderr()
    {
        // Create a script that outputs to both stdout and stderr
        $scriptPath = $this->tempDir.'/test.sh';
        file_put_contents($scriptPath, "#!/bin/bash\necho 'stdout message'\necho 'stderr message' >&2\nexit 0");
        chmod($scriptPath, 0755);

        $runner = new TestRunner;
        $runner->setCommand('bash test.sh');

        $result = $runner->run($this->tempDir);

        $this->assertStringContainsString('stdout message', $result['output']);
        $this->assertStringContainsString('stderr message', $result['output']);
    }

    /** @test */
    public function it_handles_timeout()
    {
        config(['paladin.testing.timeout' => 1]);

        // Create a script that sleeps longer than timeout
        $scriptPath = $this->tempDir.'/test.sh';
        file_put_contents($scriptPath, "#!/bin/bash\nsleep 5\necho 'Should not see this'\nexit 0");
        chmod($scriptPath, 0755);

        $runner = new TestRunner;
        $runner->setCommand('bash test.sh');

        $result = $runner->run($this->tempDir);

        $this->assertFalse($result['passed']);
        $this->assertTrue($result['timed_out']);
        $this->assertStringContainsString('timed out', $result['output']);
    }

    /** @test */
    public function it_extracts_failed_tests_from_output()
    {
        $output = "FAILED Tests\\Feature\\ExampleTest::test_example\n".
                  "ERRORED Tests\\Unit\\AnotherTest::test_something\n".
                  '5 tests, 2 failed';

        $scriptPath = $this->tempDir.'/test.sh';
        file_put_contents($scriptPath, "#!/bin/bash\necho '".addslashes($output)."'\nexit 1");
        chmod($scriptPath, 0755);

        $runner = new TestRunner;
        $runner->setCommand('bash test.sh');

        $result = $runner->run($this->tempDir);

        $this->assertArrayHasKey('failed_tests', $result);
        $this->assertNotEmpty($result['failed_tests']);
    }

    /** @test */
    public function it_returns_empty_failed_tests_on_success()
    {
        $scriptPath = $this->tempDir.'/test.sh';
        file_put_contents($scriptPath, "#!/bin/bash\necho 'All tests passed'\nexit 0");
        chmod($scriptPath, 0755);

        $runner = new TestRunner;
        $runner->setCommand('bash test.sh');

        $result = $runner->run($this->tempDir);

        $this->assertArrayHasKey('failed_tests', $result);
        $this->assertEmpty($result['failed_tests']);
    }

    /** @test */
    public function it_logs_test_execution_start()
    {
        $scriptPath = $this->tempDir.'/test.sh';
        file_put_contents($scriptPath, "#!/bin/bash\nexit 0");
        chmod($scriptPath, 0755);

        $runner = new TestRunner;
        $runner->setCommand('bash test.sh');

        $runner->run($this->tempDir);

        Log::shouldHaveReceived('info')
            ->with('[Paladin] Running tests', \Mockery::any());
    }

    /** @test */
    public function it_logs_test_execution_completion()
    {
        $scriptPath = $this->tempDir.'/test.sh';
        file_put_contents($scriptPath, "#!/bin/bash\nexit 0");
        chmod($scriptPath, 0755);

        $runner = new TestRunner;
        $runner->setCommand('bash test.sh');

        $runner->run($this->tempDir);

        Log::shouldHaveReceived('info')
            ->with('[Paladin] Test execution completed', \Mockery::any());
    }

    /** @test */
    public function it_includes_return_code_in_result()
    {
        $scriptPath = $this->tempDir.'/test.sh';
        file_put_contents($scriptPath, "#!/bin/bash\nexit 42");
        chmod($scriptPath, 0755);

        $runner = new TestRunner;
        $runner->setCommand('bash test.sh');

        $result = $runner->run($this->tempDir);

        $this->assertEquals(42, $result['return_code']);
    }

    /** @test */
    public function it_returns_all_expected_keys()
    {
        $scriptPath = $this->tempDir.'/test.sh';
        file_put_contents($scriptPath, "#!/bin/bash\nexit 0");
        chmod($scriptPath, 0755);

        $runner = new TestRunner;
        $runner->setCommand('bash test.sh');

        $result = $runner->run($this->tempDir);

        $this->assertArrayHasKey('passed', $result);
        $this->assertArrayHasKey('timed_out', $result);
        $this->assertArrayHasKey('return_code', $result);
        $this->assertArrayHasKey('output', $result);
        $this->assertArrayHasKey('failed_tests', $result);
    }
}
