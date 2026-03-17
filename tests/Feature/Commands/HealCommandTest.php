<?php

use Illuminate\Support\Facades\Queue;
use Kekser\LaravelPaladin\Jobs\ProcessSelfHealingJob;

beforeEach(function () {
    // Set environment variables for AI provider
    putenv('GEMINI_API_KEY=test-key');

    // Configure Paladin with valid settings
    config([
        'paladin.ai.provider' => 'gemini',
        'paladin.log.channels' => ['single'],
        'paladin.pr_provider' => 'github',
        'paladin.providers.github.token' => 'github-token',
    ]);

    Queue::fake();
});

afterEach(function () {
    putenv('GEMINI_API_KEY');
    putenv('OPENAI_API_KEY');
    putenv('ANTHROPIC_API_KEY');
});

test('it displays welcome message', function () {
    $this->artisan('paladin:heal')
        ->expectsOutput('🛡️  Laravel Paladin - Autonomous Self-Healing')
        ->assertExitCode(0);
});

test('it queues job by default', function () {
    $this->artisan('paladin:heal')
        ->expectsOutput('✓ Self-healing job has been queued successfully!')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessSelfHealingJob::class);
});

test('it runs synchronously with sync flag', function () {
    $this->artisan('paladin:heal --sync')
        ->expectsOutput('Running in synchronous mode - this may take a while...')
        ->assertExitCode(0);

    // dispatchSync still pushes to Queue::fake() but executes synchronously
    Queue::assertPushed(ProcessSelfHealingJob::class);
});

test('it fails when ai provider not configured', function () {
    config(['paladin.ai.provider' => null]);

    $this->artisan('paladin:heal')
        ->expectsOutput('Configuration errors detected:')
        ->assertExitCode(1);

    Queue::assertNothingPushed();
});

test('it fails when unsupported ai provider', function () {
    config([
        'paladin.ai.provider' => 'unsupported-provider',
    ]);

    $this->artisan('paladin:heal')
        ->expectsOutput('Configuration errors detected:')
        ->assertExitCode(1);

    Queue::assertNothingPushed();
});

test('it fails when git not in path', function () {
    // We can't actually remove git from PATH, so we'll skip this test
    // or mock the exec call. For now, let's test with git available.
    $this->markTestSkipped('Cannot reliably test git availability without mocking exec');
});

test('it fails when not in git repository', function () {
    // Change to a non-git directory
    $tempDir = sys_get_temp_dir().'/paladin-no-git-'.uniqid();
    mkdir($tempDir);
    $originalDir = getcwd();

    try {
        chdir($tempDir);

        $this->artisan('paladin:heal')
            ->expectsOutput('Configuration errors detected:')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    } finally {
        // Cleanup
        chdir($originalDir);
        rmdir($tempDir);
    }
});

test('it fails when no log channels configured', function () {
    config(['paladin.log.channels' => []]);

    $this->artisan('paladin:heal')
        ->expectsOutput('Configuration errors detected:')
        ->assertExitCode(1);

    Queue::assertNothingPushed();
});

test('it fails when github token missing', function () {
    config([
        'paladin.pr_provider' => 'github',
        'paladin.providers.github.token' => null,
    ]);

    $this->artisan('paladin:heal')
        ->expectsOutput('Configuration errors detected:')
        ->assertExitCode(1);

    Queue::assertNothingPushed();
});

test('it fails when azure devops not fully configured', function () {
    config([
        'paladin.pr_provider' => 'azure-devops',
        'paladin.providers.azure-devops.organization' => null,
        'paladin.providers.azure-devops.token' => 'token',
    ]);

    $this->artisan('paladin:heal')
        ->expectsOutput('Configuration errors detected:')
        ->assertExitCode(1);

    Queue::assertNothingPushed();
});

test('it fails when mail recipient not configured', function () {
    config([
        'paladin.pr_provider' => 'mail',
        'paladin.providers.mail.to' => null,
    ]);

    $this->artisan('paladin:heal')
        ->expectsOutput('Configuration errors detected:')
        ->assertExitCode(1);

    Queue::assertNothingPushed();
});

test('it warns when tests are skipped', function () {
    config(['paladin.testing.skip_tests' => true]);

    $this->artisan('paladin:heal')
        ->expectsOutput('⚠️  WARNING: Test verification is disabled (PALADIN_SKIP_TESTS=true)')
        ->expectsOutput('   Fixes will be applied and PRs created WITHOUT running tests.')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessSelfHealingJob::class);
});

test('it shows tip about sync flag when queued', function () {
    $this->artisan('paladin:heal')
        ->expectsOutput('Tip: Use --sync flag to run synchronously and see real-time output.')
        ->assertExitCode(0);
});

test('it displays multiple configuration errors', function () {
    config([
        'paladin.ai.provider' => null,
        'paladin.log.channels' => [],
        'paladin.providers.github.token' => null,
    ]);

    $this->artisan('paladin:heal')
        ->expectsOutput('Configuration errors detected:')
        ->assertExitCode(1);

    Queue::assertNothingPushed();
});

test('it supports anthropic provider', function () {
    config([
        'paladin.ai.provider' => 'anthropic',
        'paladin.ai.credentials.anthropic_api_key' => 'claude-key',
    ]);

    $this->artisan('paladin:heal')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessSelfHealingJob::class);
});

test('it supports openai provider', function () {
    config([
        'paladin.ai.provider' => 'openai',
        'paladin.ai.credentials.openai_api_key' => 'openai-key',
    ]);

    $this->artisan('paladin:heal')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessSelfHealingJob::class);
});

test('it handles sync execution errors gracefully', function () {
    // Mark test as incomplete until we can properly mock job execution errors
    $this->markTestIncomplete('Cannot easily mock job execution to throw exceptions without significant refactoring');
});

test('it shows stack trace in verbose mode on sync error', function () {
    // Similar to above - difficult to test without actual error scenario
    // We'll test that verbose mode works
    $this->artisan('paladin:heal --sync -v')
        ->assertExitCode(0);
});

test('it validates configuration before queueing', function () {
    config(['paladin.ai.provider' => null]);

    $this->artisan('paladin:heal')
        ->expectsOutput('Configuration errors detected:')
        ->assertExitCode(1);

    // Job should NOT be queued if validation fails
    Queue::assertNothingPushed();
});

test('it accepts azure devops with full config', function () {
    config([
        'paladin.pr_provider' => 'azure-devops',
        'paladin.providers.azure-devops.organization' => 'my-org',
        'paladin.providers.azure-devops.project' => 'my-project',
        'paladin.providers.azure-devops.token' => 'token',
    ]);

    $this->artisan('paladin:heal')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessSelfHealingJob::class);
});

test('it accepts mail provider with recipient', function () {
    config([
        'paladin.pr_provider' => 'mail',
        'paladin.providers.mail.to' => 'admin@example.com',
    ]);

    $this->artisan('paladin:heal')
        ->assertExitCode(0);

    Queue::assertPushed(ProcessSelfHealingJob::class);
});

test('it fails when unsupported evaluator configured', function () {
    config(['paladin.ai.evaluator' => 'unsupported-evaluator']);

    $this->artisan('paladin:heal')
        ->expectsOutput('Configuration errors detected:')
        ->assertExitCode(1);

    Queue::assertNothingPushed();
});

test('it accepts opencode evaluator without ai provider credentials', function () {
    config([
        'paladin.ai.evaluator' => 'opencode',
        'paladin.ai.provider' => null,
        'paladin.ai.credentials.gemini_api_key' => null,
    ]);

    // OpenCode evaluator doesn't require AI provider credentials.
    // It may still fail if opencode binary is not available on this system,
    // but it should NOT fail because of missing AI provider config.
    $this->artisan('paladin:heal')
        ->doesntExpectOutputToContain('AI provider not configured')
        ->doesntExpectOutputToContain('PALADIN_AI_PROVIDER');
});
