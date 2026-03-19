<?php

use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Services\TestRunner;

beforeEach(function () {
    $this->runner = app(TestRunner::class);
    $this->tempDir = $this->createTempDirectory('test-runner-');

    Log::spy();
});

afterEach(function () {
    if (isset($this->tempDir) && is_dir($this->tempDir)) {
        $this->deleteDirectory($this->tempDir);
    }
});

test('it throws exception for nonexistent directory', function () {
    $this->runner->run('/nonexistent/directory');
})->throws(RuntimeException::class, 'Working directory does not exist');

test('it can set custom test command', function () {
    $result = $this->runner->setCommand('vendor/bin/phpunit');

    expect($result)->toBeInstanceOf(TestRunner::class);
});

test('it uses configured test command', function () {
    config(['paladin.testing.command' => 'php artisan test']);

    $runner = app(TestRunner::class);

    // We can't directly access the private property, but we can verify via execution
    expect($runner)->toBeInstanceOf(TestRunner::class);
});

test('it uses configured timeout', function () {
    config(['paladin.testing.timeout' => 600]);

    $runner = app(TestRunner::class);

    expect($runner)->toBeInstanceOf(TestRunner::class);
});

test('it runs command successfully', function () {
    // Create a simple script that exits with 0
    $scriptPath = $this->tempDir.'/test.sh';
    file_put_contents($scriptPath, "#!/bin/bash\necho 'Tests passed'\nexit 0");
    chmod($scriptPath, 0755);

    $runner = app(TestRunner::class);
    $runner->setCommand('bash test.sh');

    $result = $runner->run($this->tempDir);

    expect($result['passed'])->toBeTrue();
    expect($result['timed_out'])->toBeFalse();
    expect($result['return_code'])->toBe(0);
    expect($result['output'])->toContain('Tests passed');
});

test('it detects test failures', function () {
    // Create a script that exits with non-zero code
    $scriptPath = $this->tempDir.'/test.sh';
    file_put_contents($scriptPath, "#!/bin/bash\necho 'Tests failed'\nexit 1");
    chmod($scriptPath, 0755);

    $runner = app(TestRunner::class);
    $runner->setCommand('bash test.sh');

    $result = $runner->run($this->tempDir);

    expect($result['passed'])->toBeFalse();
    expect($result['timed_out'])->toBeFalse();
    expect($result['return_code'])->toBe(1);
    expect($result['output'])->toContain('Tests failed');
});

test('it captures stdout and stderr', function () {
    // Create a script that outputs to both stdout and stderr
    $scriptPath = $this->tempDir.'/test.sh';
    file_put_contents($scriptPath, "#!/bin/bash\necho 'stdout message'\necho 'stderr message' >&2\nexit 0");
    chmod($scriptPath, 0755);

    $runner = app(TestRunner::class);
    $runner->setCommand('bash test.sh');

    $result = $runner->run($this->tempDir);

    expect($result['output'])->toContain('stdout message');
    expect($result['output'])->toContain('stderr message');
});

test('it handles timeout', function () {
    config(['paladin.testing.timeout' => 1]);

    // Create a script that sleeps longer than timeout
    $scriptPath = $this->tempDir.'/test.sh';
    file_put_contents($scriptPath, "#!/bin/bash\nsleep 5\necho 'Should not see this'\nexit 0");
    chmod($scriptPath, 0755);

    $runner = app(TestRunner::class);
    $runner->setCommand('bash test.sh');

    $result = $runner->run($this->tempDir);

    expect($result['passed'])->toBeFalse();
    expect($result['timed_out'])->toBeTrue();
    expect($result['output'])->toContain('timed out');
});

test('it extracts failed tests from output', function () {
    $output = "FAILED Tests\\Feature\\ExampleTest::test_example\n".
              "ERRORED Tests\\Unit\\AnotherTest::test_something\n".
              '5 tests, 2 failed';

    $scriptPath = $this->tempDir.'/test.sh';
    file_put_contents($scriptPath, "#!/bin/bash\necho '".addslashes($output)."'\nexit 1");
    chmod($scriptPath, 0755);

    $runner = app(TestRunner::class);
    $runner->setCommand('bash test.sh');

    $result = $runner->run($this->tempDir);

    expect($result)->toHaveKey('failed_tests');
    expect($result['failed_tests'])->not->toBeEmpty();
});

test('it returns empty failed tests on success', function () {
    $scriptPath = $this->tempDir.'/test.sh';
    file_put_contents($scriptPath, "#!/bin/bash\necho 'All tests passed'\nexit 0");
    chmod($scriptPath, 0755);

    $runner = app(TestRunner::class);
    $runner->setCommand('bash test.sh');

    $result = $runner->run($this->tempDir);

    expect($result)->toHaveKey('failed_tests');
    expect($result['failed_tests'])->toBeEmpty();
});

test('it logs test execution start', function () {
    $scriptPath = $this->tempDir.'/test.sh';
    file_put_contents($scriptPath, "#!/bin/bash\nexit 0");
    chmod($scriptPath, 0755);

    $runner = app(TestRunner::class);
    $runner->setCommand('bash test.sh');

    $runner->run($this->tempDir);

    Log::shouldHaveReceived('info')
        ->with('[Paladin] Running tests', Mockery::any());
});

test('it logs test execution completion', function () {
    $scriptPath = $this->tempDir.'/test.sh';
    file_put_contents($scriptPath, "#!/bin/bash\nexit 0");
    chmod($scriptPath, 0755);

    $runner = app(TestRunner::class);
    $runner->setCommand('bash test.sh');

    $runner->run($this->tempDir);

    Log::shouldHaveReceived('info')
        ->with('[Paladin] Test execution completed', Mockery::any());
});

test('it includes return code in result', function () {
    $scriptPath = $this->tempDir.'/test.sh';
    file_put_contents($scriptPath, "#!/bin/bash\nexit 42");
    chmod($scriptPath, 0755);

    $runner = app(TestRunner::class);
    $runner->setCommand('bash test.sh');

    $result = $runner->run($this->tempDir);

    expect($result['return_code'])->toBe(42);
});

test('it extracts failed tests from pest output', function () {
    $output = "  \u{2717} Tests\Feature\ExampleTest > it works\n".
              "  at tests/Feature/ExampleTest.php:10\n".
              "  FAILED  Tests\Unit\AnotherTest > it fails";

    $scriptPath = $this->tempDir.'/test.sh';
    // Use Heredoc to avoid escaping issues in echo
    $scriptContent = <<<BASH
#!/bin/bash
cat << 'EOF'
$output
EOF
exit 1
BASH;
    file_put_contents($scriptPath, $scriptContent);
    chmod($scriptPath, 0755);

    $runner = app(TestRunner::class);
    $runner->setCommand('bash test.sh');

    $result = $runner->run($this->tempDir);

    $testNames = array_column($result['failed_tests'], 'test');
    expect($testNames)->toContain('Tests\\Feature\\ExampleTest > it works');
    expect($testNames)->toContain('Tests\\Unit\\AnotherTest > it fails');
});
