<?php

use Kekser\LaravelPaladin\Ai\Opencode\Agents\IssueAnalyzer;
use Kekser\LaravelPaladin\Services\OpenCodeRunner;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->runnerMock = Mockery::mock(OpenCodeRunner::class);
    $this->analyzer = new IssueAnalyzer();

    // Inject mock via reflection
    $reflection = new \ReflectionClass(IssueAnalyzer::class);
    $property = $reflection->getProperty('runner');
    $property->setAccessible(true);
    $property->setValue($this->analyzer, $this->runnerMock);

    Log::spy();
});

test('it analyzes log entries successfully', function () {
    $logEntries = [
        [
            'timestamp' => 1710681600,
            'level' => 'error',
            'message' => 'Something went wrong',
            'stack_trace' => '#0 file.php(10)',
        ]
    ];

    $jsonOutput = json_encode([
        'issues' => [
            [
                'id' => 'hash',
                'type' => 'ErrorException',
                'severity' => 'high',
                'title' => 'Title',
                'message' => 'Message',
                'stack_trace' => 'Trace',
                'affected_files' => ['file.php'],
                'suggested_fix' => 'Fix',
                'log_level' => 'error'
            ]
        ]
    ]);

    $this->runnerMock->shouldReceive('run')
        ->once()
        ->andReturn([
            'success' => true,
            'stdout' => $jsonOutput,
            'return_code' => 0,
            'output' => $jsonOutput,
        ]);

    $issues = $this->analyzer->analyze($logEntries);

    expect($issues)->toHaveCount(1);
    expect($issues[0]['type'])->toBe('ErrorException');
});

test('it handles opencode failure', function () {
    $logEntries = [['message' => 'test']];

    $this->runnerMock->shouldReceive('run')
        ->once()
        ->andReturn([
            'success' => false,
            'return_code' => 1,
            'output' => 'Opencode error',
        ]);

    $this->analyzer->analyze($logEntries);
})->throws(RuntimeException::class, 'OpenCode issue analysis failed: Opencode error');

test('it extracts json from markdown code fences', function () {
    $logEntries = [['message' => 'test']];
    $output = "Here is the analysis:\n```json\n" . json_encode([
        'issues' => [['id' => '1']]
    ]) . "\n```\nHope this helps!";

    $this->runnerMock->shouldReceive('run')
        ->once()
        ->andReturn([
            'success' => true,
            'stdout' => $output,
            'return_code' => 0,
            'output' => $output,
        ]);

    $issues = $this->analyzer->analyze($logEntries);
    expect($issues)->toHaveCount(1);
    expect($issues[0]['id'])->toBe('1');
});

test('it extracts json by finding issues key', function () {
    $logEntries = [['message' => 'test']];
    $json = json_encode([
        'issues' => [['id' => '2']]
    ]);
    $output = "Some text before " . $json . " some text after";

    $this->runnerMock->shouldReceive('run')
        ->once()
        ->andReturn([
            'success' => true,
            'stdout' => $output,
            'return_code' => 0,
            'output' => $output,
        ]);

    $issues = $this->analyzer->analyze($logEntries);
    expect($issues)->toHaveCount(1);
    expect($issues[0]['id'])->toBe('2');
});

test('it throws exception if json not found', function () {
    $logEntries = [['message' => 'test']];
    $output = "No JSON here";

    $this->runnerMock->shouldReceive('run')
        ->once()
        ->andReturn([
            'success' => true,
            'stdout' => $output,
            'return_code' => 0,
            'output' => $output,
        ]);

    $this->analyzer->analyze($logEntries);
})->throws(RuntimeException::class, 'Failed to parse analysis output as valid JSON');

test('it handles json with balanced braces edge cases', function () {
    $logEntries = [['message' => 'test']];

    // Case where it finds "issues" but cannot find balanced braces
    $output = 'Some text "issues" without closing brace {';

    $this->runnerMock->shouldReceive('run')
        ->once()
        ->andReturn([
            'success' => true,
            'stdout' => $output,
            'return_code' => 0,
            'output' => $output,
        ]);

    try {
        $this->analyzer->analyze($logEntries);
        $this->fail('Expected RuntimeException was not thrown');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Failed to parse analysis output as valid JSON');
    }

    // Case where it finds balanced braces but they don't contain valid JSON or "issues" key
    $output = 'Some text "issues" { invalid json }';
    $this->runnerMock->shouldReceive('run')
        ->once()
        ->andReturn([
            'success' => true,
            'stdout' => $output,
            'return_code' => 0,
            'output' => $output,
        ]);

    try {
        $this->analyzer->analyze($logEntries);
        $this->fail('Expected RuntimeException was not thrown');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('Failed to parse analysis output as valid JSON');
    }
});

test('it handles complex json extraction', function () {
    $logEntries = [['message' => 'test']];

    // This output has a nested JSON-like structure after "issues"
    $output = 'Some text { "data": { "nested": 123 }, "issues": [{"id":"3"}] } and more text';

    $this->runnerMock->shouldReceive('run')
        ->once()
        ->andReturn([
            'success' => true,
            'stdout' => $output,
            'return_code' => 0,
            'output' => $output,
        ]);

    $issues = $this->analyzer->analyze($logEntries);
    expect($issues)->toHaveCount(1);
    expect($issues[0]['id'])->toBe('3');
});
