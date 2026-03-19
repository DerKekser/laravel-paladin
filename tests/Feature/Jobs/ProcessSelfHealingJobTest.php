<?php

namespace Tests\Feature\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Ai\EvaluatorFactory;
use Kekser\LaravelPaladin\Contracts\IssueEvaluator;
use Kekser\LaravelPaladin\Jobs\ProcessSelfHealingJob;
use Kekser\LaravelPaladin\Services\IssuePrioritizer;
use Kekser\LaravelPaladin\Services\LogScanner;
use Kekser\LaravelPaladin\Services\OpenCodeInstaller;
use Kekser\LaravelPaladin\Services\SelfHealingOrchestrator;
use Mockery;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'paladin.queue.connection' => 'sync',
        'paladin.queue.queue' => 'default',
        'paladin.issues.max_per_run' => 5,
        'paladin.testing.max_fix_attempts' => 3,
        'paladin.git.branch_prefix' => 'paladin/fix',
        'paladin.git.commit_message_template' => 'Fix {issue_title}',
        'paladin.git.pr_title_template' => 'Fix {issue_title}',
        'paladin.git.pr_body_template' => 'Fixes {issue_description}',
    ]);

    // Mock the AI evaluator
    $this->mockEvaluator = Mockery::mock(IssueEvaluator::class);
    $this->mockEvaluatorFactory = Mockery::mock(EvaluatorFactory::class);
    $this->mockEvaluatorFactory->shouldReceive('create')->andReturn($this->mockEvaluator);
    $this->app->instance(EvaluatorFactory::class, $this->mockEvaluatorFactory);
});

it('skips processing if no log entries found', function () {
    $mockScanner = Mockery::mock(LogScanner::class);
    $mockScanner->shouldReceive('scan')->andReturn([]);
    $this->app->instance(LogScanner::class, $mockScanner);

    $mockInstaller = Mockery::mock(OpenCodeInstaller::class);
    $mockInstaller->shouldReceive('isInstalled')->andReturn(true);
    $mockInstaller->shouldReceive('getVersion')->andReturn('1.0.0');
    $this->app->instance(OpenCodeInstaller::class, $mockInstaller);

    Log::shouldReceive('info')->with('[Paladin] Starting self-healing process')->once();
    Log::shouldReceive('info')->with('[Paladin] OpenCode is installed', ['version' => '1.0.0'])->once();
    Log::shouldReceive('info')->with('[Paladin] Scanning logs for new entries')->once();
    Log::shouldReceive('info')->with('[Paladin] Found log entries', ['count' => 0])->once();
    Log::shouldReceive('info')->with('[Paladin] No new log entries found')->once();

    ProcessSelfHealingJob::dispatch();

    expect(true)->toBeTrue();
});

it('processes issues through the orchestrator', function () {
    $issue = [
        'id' => 'issue-1',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'Something went wrong',
        'title' => 'Test Issue',
        'affected_files' => ['app/Models/User.php'],
    ];

    // Mock LogScanner
    $mockScanner = Mockery::mock(LogScanner::class);
    $mockScanner->shouldReceive('scan')->andReturn([['message' => 'Something went wrong']]);
    $this->app->instance(LogScanner::class, $mockScanner);

    // Mock Installer
    $mockInstaller = Mockery::mock(OpenCodeInstaller::class);
    $mockInstaller->shouldReceive('isInstalled')->andReturn(true);
    $mockInstaller->shouldReceive('getVersion')->andReturn('1.0.0');
    $this->app->instance(OpenCodeInstaller::class, $mockInstaller);

    // Mock Evaluator
    $this->mockEvaluator->shouldReceive('analyzeIssues')->andReturn([$issue]);

    // Mock IssuePrioritizer
    $mockPrioritizer = Mockery::mock(IssuePrioritizer::class);
    $mockPrioritizer->shouldReceive('prioritize')->with([$issue])->andReturn([$issue]);
    $this->app->instance(IssuePrioritizer::class, $mockPrioritizer);

    // Mock Orchestrator - expect to be called with issues and empty specificIssues
    $mockOrchestrator = Mockery::mock(SelfHealingOrchestrator::class);
    $mockOrchestrator->shouldReceive('processIssues')
        ->once()
        ->with([$issue], []);
    $this->app->instance(SelfHealingOrchestrator::class, $mockOrchestrator);

    Log::shouldReceive('info')->with('[Paladin] Starting self-healing process')->once();
    Log::shouldReceive('info')->with('[Paladin] OpenCode is installed', Mockery::any())->once();
    Log::shouldReceive('info')->with('[Paladin] Scanning logs for new entries')->once();
    Log::shouldReceive('info')->with('[Paladin] Found log entries', Mockery::any())->once();
    Log::shouldReceive('info')->with('[Paladin] Analyzing issues with AI')->once();
    Log::shouldReceive('info')->with('[Paladin] Issues analyzed and prioritized', Mockery::any())->once();
    Log::shouldReceive('info')->with('[Paladin] Self-healing process completed')->once();

    ProcessSelfHealingJob::dispatch();
});

it('filters to specific issues when provided', function () {
    $issue1 = [
        'id' => 'issue-1',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'Something went wrong',
        'title' => 'Test Issue',
        'affected_files' => ['app/Models/User.php'],
    ];

    $issue2 = [
        'id' => 'issue-2',
        'type' => 'error',
        'severity' => 'medium',
        'message' => 'Another issue',
        'title' => 'Another Issue',
        'affected_files' => ['app/Models/Post.php'],
    ];

    // Mock LogScanner
    $mockScanner = Mockery::mock(LogScanner::class);
    $mockScanner->shouldReceive('scan')->andReturn([['message' => 'Something went wrong']]);
    $this->app->instance(LogScanner::class, $mockScanner);

    // Mock Installer
    $mockInstaller = Mockery::mock(OpenCodeInstaller::class);
    $mockInstaller->shouldReceive('isInstalled')->andReturn(true);
    $mockInstaller->shouldReceive('getVersion')->andReturn('1.0.0');
    $this->app->instance(OpenCodeInstaller::class, $mockInstaller);

    // Mock Evaluator to return both issues
    $this->mockEvaluator->shouldReceive('analyzeIssues')->andReturn([$issue1, $issue2]);

    // Mock IssuePrioritizer
    $mockPrioritizer = Mockery::mock(IssuePrioritizer::class);
    $mockPrioritizer->shouldReceive('prioritize')->andReturn([$issue1, $issue2]);
    $this->app->instance(IssuePrioritizer::class, $mockPrioritizer);

    // Mock Orchestrator - expect to be called with both issues but filtering for issue-1
    $mockOrchestrator = Mockery::mock(SelfHealingOrchestrator::class);
    $mockOrchestrator->shouldReceive('processIssues')
        ->once()
        ->with([$issue1, $issue2], ['issue-1']);
    $this->app->instance(SelfHealingOrchestrator::class, $mockOrchestrator);

    ProcessSelfHealingJob::dispatch(['issue-1']);
});

it('handles exceptions during the self-healing process', function () {
    $mockScanner = Mockery::mock(LogScanner::class);
    $mockScanner->shouldReceive('scan')->andThrow(new \Exception('Test exception'));
    $this->app->instance(LogScanner::class, $mockScanner);

    $mockInstaller = Mockery::mock(OpenCodeInstaller::class);
    $mockInstaller->shouldReceive('isInstalled')->andReturn(true);
    $mockInstaller->shouldReceive('getVersion')->andReturn('1.0.0');
    $this->app->instance(OpenCodeInstaller::class, $mockInstaller);

    Log::shouldReceive('info')->with('[Paladin] Starting self-healing process')->once();
    Log::shouldReceive('info')->with('[Paladin] OpenCode is installed', Mockery::any())->once();
    Log::shouldReceive('info')->with('[Paladin] Scanning logs for new entries')->once();
    Log::shouldReceive('error')->withArgs(function ($message, $context) {
        return $message === '[Paladin] Self-healing process failed' && $context['error'] === 'Test exception';
    })->once();

    expect(fn () => ProcessSelfHealingJob::dispatch())->toThrow(\Exception::class, 'Test exception');
});

it('handles installer success and version info', function () {
    $mockScanner = Mockery::mock(LogScanner::class);
    $mockScanner->shouldReceive('scan')->andReturn([]);
    $this->app->instance(LogScanner::class, $mockScanner);

    $mockInstaller = Mockery::mock(OpenCodeInstaller::class);
    $mockInstaller->shouldReceive('isInstalled')->andReturn(false);
    $mockInstaller->shouldReceive('ensureInstalled')->once();
    $this->app->instance(OpenCodeInstaller::class, $mockInstaller);

    Log::shouldReceive('info')->with('[Paladin] Starting self-healing process')->once();
    Log::shouldReceive('info')->with('[Paladin] OpenCode not installed, attempting installation')->once();
    Log::shouldReceive('info')->with('[Paladin] Scanning logs for new entries')->once();
    Log::shouldReceive('info')->with('[Paladin] Found log entries', Mockery::any())->once();
    Log::shouldReceive('info')->with('[Paladin] No new log entries found')->once();

    ProcessSelfHealingJob::dispatch();

    expect(true)->toBeTrue();
});

it('returns early when no actionable issues found', function () {
    // Mock LogScanner
    $mockScanner = Mockery::mock(LogScanner::class);
    $mockScanner->shouldReceive('scan')->andReturn([['message' => 'Something went wrong']]);
    $this->app->instance(LogScanner::class, $mockScanner);

    // Mock Installer
    $mockInstaller = Mockery::mock(OpenCodeInstaller::class);
    $mockInstaller->shouldReceive('isInstalled')->andReturn(true);
    $mockInstaller->shouldReceive('getVersion')->andReturn('1.0.0');
    $this->app->instance(OpenCodeInstaller::class, $mockInstaller);

    // Mock Evaluator to return no issues
    $this->mockEvaluator->shouldReceive('analyzeIssues')->andReturn([]);

    Log::shouldReceive('info')->with('[Paladin] Starting self-healing process')->once();
    Log::shouldReceive('info')->with('[Paladin] OpenCode is installed', Mockery::any())->once();
    Log::shouldReceive('info')->with('[Paladin] Scanning logs for new entries')->once();
    Log::shouldReceive('info')->with('[Paladin] Found log entries', Mockery::any())->once();
    Log::shouldReceive('info')->with('[Paladin] Analyzing issues with AI')->once();
    Log::shouldReceive('info')->with('[Paladin] No actionable issues found')->once();

    ProcessSelfHealingJob::dispatch();

    expect(true)->toBeTrue();
});
