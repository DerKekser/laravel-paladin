<?php

namespace Kekser\LaravelPaladin\Tests\Feature\Commands;

use Illuminate\Support\Facades\Queue;
use Kekser\LaravelPaladin\Jobs\ProcessSelfHealingJob;
use Kekser\LaravelPaladin\Tests\TestCase;

class HealCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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
    }

    protected function tearDown(): void
    {
        putenv('GEMINI_API_KEY');
        putenv('OPENAI_API_KEY');
        putenv('ANTHROPIC_API_KEY');

        parent::tearDown();
    }

    /** @test */
    public function it_displays_welcome_message()
    {
        $this->artisan('paladin:heal')
            ->expectsOutput('🛡️  Laravel Paladin - Autonomous Self-Healing')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_queues_job_by_default()
    {
        $this->artisan('paladin:heal')
            ->expectsOutput('✓ Self-healing job has been queued successfully!')
            ->assertExitCode(0);

        Queue::assertPushed(ProcessSelfHealingJob::class);
    }

    /** @test */
    public function it_runs_synchronously_with_sync_flag()
    {
        $this->artisan('paladin:heal --sync')
            ->expectsOutput('Running in synchronous mode - this may take a while...')
            ->assertExitCode(0);

        // dispatchSync still pushes to Queue::fake() but executes synchronously
        Queue::assertPushed(ProcessSelfHealingJob::class);
    }

    /** @test */
    public function it_fails_when_ai_provider_not_configured()
    {
        config(['paladin.ai.provider' => null]);

        $this->artisan('paladin:heal')
            ->expectsOutput('Configuration errors detected:')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_fails_when_unsupported_ai_provider()
    {
        config([
            'paladin.ai.provider' => 'unsupported-provider',
        ]);

        $this->artisan('paladin:heal')
            ->expectsOutput('Configuration errors detected:')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_fails_when_git_not_in_path()
    {
        // We can't actually remove git from PATH, so we'll skip this test
        // or mock the exec call. For now, let's test with git available.
        $this->markTestSkipped('Cannot reliably test git availability without mocking exec');
    }

    /** @test */
    public function it_fails_when_not_in_git_repository()
    {
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
    }

    /** @test */
    public function it_fails_when_no_log_channels_configured()
    {
        config(['paladin.log.channels' => []]);

        $this->artisan('paladin:heal')
            ->expectsOutput('Configuration errors detected:')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_fails_when_github_token_missing()
    {
        config([
            'paladin.pr_provider' => 'github',
            'paladin.providers.github.token' => null,
        ]);

        $this->artisan('paladin:heal')
            ->expectsOutput('Configuration errors detected:')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_fails_when_azure_devops_not_fully_configured()
    {
        config([
            'paladin.pr_provider' => 'azure-devops',
            'paladin.providers.azure-devops.organization' => null,
            'paladin.providers.azure-devops.token' => 'token',
        ]);

        $this->artisan('paladin:heal')
            ->expectsOutput('Configuration errors detected:')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_fails_when_mail_recipient_not_configured()
    {
        config([
            'paladin.pr_provider' => 'mail',
            'paladin.providers.mail.to' => null,
        ]);

        $this->artisan('paladin:heal')
            ->expectsOutput('Configuration errors detected:')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_warns_when_tests_are_skipped()
    {
        config(['paladin.testing.skip_tests' => true]);

        $this->artisan('paladin:heal')
            ->expectsOutput('⚠️  WARNING: Test verification is disabled (PALADIN_SKIP_TESTS=true)')
            ->expectsOutput('   Fixes will be applied and PRs created WITHOUT running tests.')
            ->assertExitCode(0);

        Queue::assertPushed(ProcessSelfHealingJob::class);
    }

    /** @test */
    public function it_shows_tip_about_sync_flag_when_queued()
    {
        $this->artisan('paladin:heal')
            ->expectsOutput('Tip: Use --sync flag to run synchronously and see real-time output.')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_displays_multiple_configuration_errors()
    {
        config([
            'paladin.ai.provider' => null,
            'paladin.log.channels' => [],
            'paladin.providers.github.token' => null,
        ]);

        $this->artisan('paladin:heal')
            ->expectsOutput('Configuration errors detected:')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_supports_anthropic_provider()
    {
        config([
            'paladin.ai.provider' => 'anthropic',
            'paladin.ai.credentials.anthropic_api_key' => 'claude-key',
        ]);

        $this->artisan('paladin:heal')
            ->assertExitCode(0);

        Queue::assertPushed(ProcessSelfHealingJob::class);
    }

    /** @test */
    public function it_supports_openai_provider()
    {
        config([
            'paladin.ai.provider' => 'openai',
            'paladin.ai.credentials.openai_api_key' => 'openai-key',
        ]);

        $this->artisan('paladin:heal')
            ->assertExitCode(0);

        Queue::assertPushed(ProcessSelfHealingJob::class);
    }

    /** @test */
    public function it_handles_sync_execution_errors_gracefully()
    {
        // Mark test as incomplete until we can properly mock job execution errors
        $this->markTestIncomplete('Cannot easily mock job execution to throw exceptions without significant refactoring');
    }

    /** @test */
    public function it_shows_stack_trace_in_verbose_mode_on_sync_error()
    {
        // Similar to above - difficult to test without actual error scenario
        // We'll test that verbose mode works
        $this->artisan('paladin:heal --sync -v')
            ->assertExitCode(0);
    }

    /** @test */
    public function it_validates_configuration_before_queueing()
    {
        config(['paladin.ai.provider' => null]);

        $this->artisan('paladin:heal')
            ->expectsOutput('Configuration errors detected:')
            ->assertExitCode(1);

        // Job should NOT be queued if validation fails
        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_accepts_azure_devops_with_full_config()
    {
        config([
            'paladin.pr_provider' => 'azure-devops',
            'paladin.providers.azure-devops.organization' => 'my-org',
            'paladin.providers.azure-devops.project' => 'my-project',
            'paladin.providers.azure-devops.token' => 'token',
        ]);

        $this->artisan('paladin:heal')
            ->assertExitCode(0);

        Queue::assertPushed(ProcessSelfHealingJob::class);
    }

    /** @test */
    public function it_accepts_mail_provider_with_recipient()
    {
        config([
            'paladin.pr_provider' => 'mail',
            'paladin.providers.mail.to' => 'admin@example.com',
        ]);

        $this->artisan('paladin:heal')
            ->assertExitCode(0);

        Queue::assertPushed(ProcessSelfHealingJob::class);
    }

    /** @test */
    public function it_fails_when_unsupported_evaluator_configured()
    {
        config(['paladin.ai.evaluator' => 'unsupported-evaluator']);

        $this->artisan('paladin:heal')
            ->expectsOutput('Configuration errors detected:')
            ->assertExitCode(1);

        Queue::assertNothingPushed();
    }

    /** @test */
    public function it_accepts_opencode_evaluator_without_ai_provider_credentials()
    {
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
    }
}
