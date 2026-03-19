<?php

namespace Tests\Feature\Jobs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Kekser\LaravelPaladin\Ai\EvaluatorFactory;
use Kekser\LaravelPaladin\Contracts\IssueEvaluator;
use Kekser\LaravelPaladin\Jobs\ProcessSelfHealingJob;
use Kekser\LaravelPaladin\Services\GitService;
use Kekser\LaravelPaladin\Services\LogScanner;
use Kekser\LaravelPaladin\Services\OpenCodeInstaller;
use Kekser\LaravelPaladin\Services\OpenCodeRunner;
use Kekser\LaravelPaladin\Services\PullRequestManager;
use Kekser\LaravelPaladin\Services\TestRunner;
use Kekser\LaravelPaladin\Services\WorktreeManager;
use Kekser\LaravelPaladin\Services\WorktreeSetup;
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

    // Mock services that are instantiated via 'new' in the job
    // Unfortunately, we can't easily mock 'new' without a factory or DI.
    // However, some services are fetched via app() in the job.

    $this->mockEvaluator = Mockery::mock(IssueEvaluator::class);
    $this->mockEvaluatorFactory = Mockery::mock(EvaluatorFactory::class);
    $this->mockEvaluatorFactory->shouldReceive('create')->andReturn($this->mockEvaluator);
    $this->app->instance(EvaluatorFactory::class, $this->mockEvaluatorFactory);

    $this->mockGitService = Mockery::mock(GitService::class);
    $this->app->instance(GitService::class, $this->mockGitService);

    $this->mockPRManager = Mockery::mock(PullRequestManager::class);
    $this->app->instance(PullRequestManager::class, $this->mockPRManager);

    // For services instantiated via 'new', we might need to mock the underlying facades or side effects
    // if we can't refactor the job. Let's see if we can use Mockery's instance mocking.
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

    expect(true)->toBeTrue(); // Generic assertion to satisfy Pest
});

it('processes issues and creates pull requests on success', function () {
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
    $this->mockEvaluator->shouldReceive('generatePrompt')->andReturn('Fix it');

    // Mock WorktreeManager
    $mockWorktreeManager = Mockery::mock(WorktreeManager::class);
    $mockWorktreeManager->shouldReceive('create')->with('issue-1')->andReturn(['path' => '/tmp/worktree']);
    $mockWorktreeManager->shouldReceive('exists')->andReturn(true);
    $mockWorktreeManager->shouldReceive('remove')->with('/tmp/worktree')->andReturn(true);
    $this->app->instance(WorktreeManager::class, $mockWorktreeManager);

    // Mock WorktreeSetup
    $mockWorktreeSetup = Mockery::mock(WorktreeSetup::class);
    $mockWorktreeSetup->shouldReceive('setup')->andReturn(true);
    $this->app->instance(WorktreeSetup::class, $mockWorktreeSetup);

    // Mock OpenCodeRunner
    $mockOpenCodeRunner = Mockery::mock(OpenCodeRunner::class);
    $mockOpenCodeRunner->shouldReceive('run')->andReturn(['success' => true, 'output' => 'Fixed']);
    $this->app->instance(OpenCodeRunner::class, $mockOpenCodeRunner);

    // Mock TestRunner
    $mockTestRunner = Mockery::mock(TestRunner::class);
    $mockTestRunner->shouldReceive('run')->andReturn(['passed' => true, 'output' => 'Tests passed']);
    $this->app->instance(TestRunner::class, $mockTestRunner);

    // Mock GitService
    $this->mockGitService->shouldReceive('createBranch')->andReturn(true);
    $this->mockGitService->shouldReceive('commit')->andReturn(true);
    $this->mockGitService->shouldReceive('hasRemote')->andReturn(true);
    $this->mockGitService->shouldReceive('push')->andReturn(true);

    // Mock PRManager
    $this->mockPRManager->shouldReceive('createPullRequest')->andReturn('https://github.com/pull/1');

    ProcessSelfHealingJob::dispatch();

    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-1',
        'status' => 'fixed',
        'pr_url' => 'https://github.com/pull/1',
    ]);
});

it('skips issues that are outside project boundaries', function () {
    $issue = [
        'id' => 'issue-external',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'Something in vendor went wrong',
        'title' => 'External Issue',
        'affected_files' => ['vendor/laravel/framework/src/Illuminate/Foundation/Application.php'],
    ];

    // Mock LogScanner
    $mockScanner = Mockery::mock(LogScanner::class);
    $mockScanner->shouldReceive('scan')->andReturn([['message' => 'External error']]);
    $this->app->instance(LogScanner::class, $mockScanner);

    // Mock Installer
    $mockInstaller = Mockery::mock(OpenCodeInstaller::class);
    $mockInstaller->shouldReceive('isInstalled')->andReturn(true);
    $mockInstaller->shouldReceive('getVersion')->andReturn('1.0.0');
    $this->app->instance(OpenCodeInstaller::class, $mockInstaller);

    // Mock Evaluator
    $this->mockEvaluator->shouldReceive('analyzeIssues')->andReturn([$issue]);

    // Mock FileBoundaryValidator
    $mockValidator = Mockery::mock(FileBoundaryValidator::class);
    $mockValidator->shouldReceive('analyzeIssue')->andReturn([
        'is_fixable' => false,
        'reason' => 'All affected files are outside project boundaries',
        'internal_files' => [],
        'external_files' => ['vendor/laravel/framework/src/Illuminate/Foundation/Application.php'],
    ]);
    $this->app->instance(FileBoundaryValidator::class, $mockValidator);

    ProcessSelfHealingJob::dispatch();

    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-external',
        'status' => 'skipped',
        'error_message' => 'All affected files are outside project boundaries: vendor/laravel/framework/src/Illuminate/Foundation/Application.php',
    ]);
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
    Log::shouldReceive('info')->with('[Paladin] OpenCode is installed', ['version' => '1.0.0'])->once();
    Log::shouldReceive('info')->with('[Paladin] Scanning logs for new entries')->once();
    Log::shouldReceive('error')->withArgs(function ($message, $context) {
        return $message === '[Paladin] Self-healing process failed' && $context['error'] === 'Test exception';
    })->once();

    expect(fn () => ProcessSelfHealingJob::dispatch())->toThrow(\Exception::class, 'Test exception');
});

it('retries when fix attempt fails and reaches max attempts', function () {
    config(['paladin.testing.max_fix_attempts' => 2]);
    $issue = [
        'id' => 'issue-retry',
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
    $this->mockEvaluator->shouldReceive('generatePrompt')->twice()->andReturn('Fix it');

    // Mock WorktreeManager
    $mockWorktreeManager = Mockery::mock(WorktreeManager::class);
    $mockWorktreeManager->shouldReceive('create')->twice()->andReturn(['path' => '/tmp/worktree']);
    $mockWorktreeManager->shouldReceive('exists')->andReturn(true);
    $mockWorktreeManager->shouldReceive('remove')->andReturn(true);
    $this->app->instance(WorktreeManager::class, $mockWorktreeManager);

    // Mock WorktreeSetup
    $mockWorktreeSetup = Mockery::mock(WorktreeSetup::class);
    $mockWorktreeSetup->shouldReceive('setup')->andReturn(true);
    $this->app->instance(WorktreeSetup::class, $mockWorktreeSetup);

    // Mock OpenCodeRunner - first fail, second fail
    $mockOpenCodeRunner = Mockery::mock(OpenCodeRunner::class);
    $mockOpenCodeRunner->shouldReceive('run')->andReturn(['success' => false, 'output' => 'Failed']);
    $this->app->instance(OpenCodeRunner::class, $mockOpenCodeRunner);

    ProcessSelfHealingJob::dispatch();

    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-retry',
        'attempt_number' => 1,
        'status' => 'failed',
    ]);
    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-retry',
        'attempt_number' => 2,
        'status' => 'failed',
    ]);
});

it('skips tests if configured', function () {
    config(['paladin.testing.skip_tests' => true]);
    $issue = [
        'id' => 'issue-skip-tests',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'Something went wrong',
        'title' => 'Test Issue',
        'affected_files' => ['app/Models/User.php'],
    ];

    $mockScanner = Mockery::mock(LogScanner::class);
    $mockScanner->shouldReceive('scan')->andReturn([['message' => 'Something went wrong']]);
    $this->app->instance(LogScanner::class, $mockScanner);

    $mockInstaller = Mockery::mock(OpenCodeInstaller::class);
    $mockInstaller->shouldReceive('isInstalled')->andReturn(true);
    $mockInstaller->shouldReceive('getVersion')->andReturn('1.0.0');
    $this->app->instance(OpenCodeInstaller::class, $mockInstaller);

    $this->mockEvaluator->shouldReceive('analyzeIssues')->andReturn([$issue]);
    $this->mockEvaluator->shouldReceive('generatePrompt')->andReturn('Fix it');

    $mockWorktreeManager = Mockery::mock(WorktreeManager::class);
    $mockWorktreeManager->shouldReceive('create')->andReturn(['path' => '/tmp/worktree']);
    $mockWorktreeManager->shouldReceive('exists')->andReturn(true);
    $mockWorktreeManager->shouldReceive('remove')->andReturn(true);
    $this->app->instance(WorktreeManager::class, $mockWorktreeManager);

    $mockWorktreeSetup = Mockery::mock(WorktreeSetup::class);
    $mockWorktreeSetup->shouldReceive('setup')->andReturn(true);
    $this->app->instance(WorktreeSetup::class, $mockWorktreeSetup);

    $mockOpenCodeRunner = Mockery::mock(OpenCodeRunner::class);
    $mockOpenCodeRunner->shouldReceive('run')->andReturn(['success' => true, 'output' => 'Fixed']);
    $this->app->instance(OpenCodeRunner::class, $mockOpenCodeRunner);

    // Mock GitService
    $this->mockGitService->shouldReceive('createBranch')->andReturn(true);
    $this->mockGitService->shouldReceive('commit')->andReturn(true);
    $this->mockGitService->shouldReceive('hasRemote')->andReturn(false);

    ProcessSelfHealingJob::dispatch();

    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-skip-tests',
        'status' => 'fixed',
        'test_output' => 'Tests skipped (PALADIN_SKIP_TESTS=true)',
    ]);
});

it('handles worktree setup failure', function () {
    config(['paladin.worktree.setup.enabled' => true]);
    $issue = [
        'id' => 'issue-setup-fail',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'Something went wrong',
        'title' => 'Test Issue',
        'affected_files' => ['app/Models/User.php'],
    ];

    $mockScanner = Mockery::mock(LogScanner::class);
    $mockScanner->shouldReceive('scan')->andReturn([['message' => 'Something went wrong']]);
    $this->app->instance(LogScanner::class, $mockScanner);

    $mockInstaller = Mockery::mock(OpenCodeInstaller::class);
    $mockInstaller->shouldReceive('isInstalled')->andReturn(true);
    $mockInstaller->shouldReceive('getVersion')->andReturn('1.0.0');
    $this->app->instance(OpenCodeInstaller::class, $mockInstaller);

    $this->mockEvaluator->shouldReceive('analyzeIssues')->andReturn([$issue]);

    $mockWorktreeManager = Mockery::mock(WorktreeManager::class);
    $mockWorktreeManager->shouldReceive('create')->andReturn(['path' => '/tmp/worktree']);
    $mockWorktreeManager->shouldReceive('exists')->andReturn(true);
    $mockWorktreeManager->shouldReceive('remove')->with('/tmp/worktree')->andReturn(true);
    $this->app->instance(WorktreeManager::class, $mockWorktreeManager);

    $mockWorktreeSetup = Mockery::mock(WorktreeSetup::class);
    $mockWorktreeSetup->shouldReceive('setup')->andReturn(false);
    $this->app->instance(WorktreeSetup::class, $mockWorktreeSetup);

    ProcessSelfHealingJob::dispatch();

    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-setup-fail',
        'status' => 'failed',
        'error_message' => 'Worktree setup failed',
    ]);
});

it('handles test failure after fix', function () {
    $issue = [
        'id' => 'issue-test-fail',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'Something went wrong',
        'title' => 'Test Issue',
        'affected_files' => ['app/Models/User.php'],
    ];

    $mockScanner = Mockery::mock(LogScanner::class);
    $mockScanner->shouldReceive('scan')->andReturn([['message' => 'Something went wrong']]);
    $this->app->instance(LogScanner::class, $mockScanner);

    $mockInstaller = Mockery::mock(OpenCodeInstaller::class);
    $mockInstaller->shouldReceive('isInstalled')->andReturn(true);
    $mockInstaller->shouldReceive('getVersion')->andReturn('1.0.0');
    $this->app->instance(OpenCodeInstaller::class, $mockInstaller);

    $this->mockEvaluator->shouldReceive('analyzeIssues')->andReturn([$issue]);
    $this->mockEvaluator->shouldReceive('generatePrompt')->andReturn('Fix it');

    $mockWorktreeManager = Mockery::mock(WorktreeManager::class);
    $mockWorktreeManager->shouldReceive('create')->andReturn(['path' => '/tmp/worktree']);
    $mockWorktreeManager->shouldReceive('exists')->andReturn(true);
    $mockWorktreeManager->shouldReceive('remove')->andReturn(true);
    $this->app->instance(WorktreeManager::class, $mockWorktreeManager);

    $mockWorktreeSetup = Mockery::mock(WorktreeSetup::class);
    $mockWorktreeSetup->shouldReceive('setup')->andReturn(true);
    $this->app->instance(WorktreeSetup::class, $mockWorktreeSetup);

    $mockOpenCodeRunner = Mockery::mock(OpenCodeRunner::class);
    $mockOpenCodeRunner->shouldReceive('run')->andReturn(['success' => true, 'output' => 'Fixed']);
    $this->app->instance(OpenCodeRunner::class, $mockOpenCodeRunner);

    $mockTestRunner = Mockery::mock(TestRunner::class);
    $mockTestRunner->shouldReceive('run')->andReturn(['passed' => false, 'output' => 'Tests failed']);
    $this->app->instance(TestRunner::class, $mockTestRunner);

    ProcessSelfHealingJob::dispatch();

    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-test-fail',
        'status' => 'failed',
        'test_output' => 'Tests failed',
    ]);
});

it('handles PR creation failure', function () {
    $issue = [
        'id' => 'issue-pr-fail',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'Something went wrong',
        'title' => 'Test Issue',
        'affected_files' => ['app/Models/User.php'],
    ];

    $mockScanner = Mockery::mock(LogScanner::class);
    $mockScanner->shouldReceive('scan')->andReturn([['message' => 'Something went wrong']]);
    $this->app->instance(LogScanner::class, $mockScanner);

    $mockInstaller = Mockery::mock(OpenCodeInstaller::class);
    $mockInstaller->shouldReceive('isInstalled')->andReturn(true);
    $mockInstaller->shouldReceive('getVersion')->andReturn('1.0.0');
    $this->app->instance(OpenCodeInstaller::class, $mockInstaller);

    $this->mockEvaluator->shouldReceive('analyzeIssues')->andReturn([$issue]);
    $this->mockEvaluator->shouldReceive('generatePrompt')->andReturn('Fix it');

    $mockWorktreeManager = Mockery::mock(WorktreeManager::class);
    $mockWorktreeManager->shouldReceive('create')->andReturn(['path' => '/tmp/worktree']);
    $mockWorktreeManager->shouldReceive('exists')->andReturn(true);
    $mockWorktreeManager->shouldReceive('remove')->andReturn(true);
    $this->app->instance(WorktreeManager::class, $mockWorktreeManager);

    $mockWorktreeSetup = Mockery::mock(WorktreeSetup::class);
    $mockWorktreeSetup->shouldReceive('setup')->andReturn(true);
    $this->app->instance(WorktreeSetup::class, $mockWorktreeSetup);

    $mockOpenCodeRunner = Mockery::mock(OpenCodeRunner::class);
    $mockOpenCodeRunner->shouldReceive('run')->andReturn(['success' => true, 'output' => 'Fixed']);
    $this->app->instance(OpenCodeRunner::class, $mockOpenCodeRunner);

    $mockTestRunner = Mockery::mock(TestRunner::class);
    $mockTestRunner->shouldReceive('run')->andReturn(['passed' => true, 'output' => 'Tests passed']);
    $this->app->instance(TestRunner::class, $mockTestRunner);

    $this->mockGitService->shouldReceive('createBranch')->andReturn(true);
    $this->mockGitService->shouldReceive('commit')->andReturn(true);
    $this->mockGitService->shouldReceive('hasRemote')->andReturn(true);
    $this->mockGitService->shouldReceive('push')->andReturn(true);

    $this->mockPRManager->shouldReceive('createPullRequest')->andReturn(null);

    ProcessSelfHealingJob::dispatch();

    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-pr-fail',
        'status' => 'failed',
        'error_message' => 'Failed to create pull request',
    ]);
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
    Log::shouldReceive('info')->with('[Paladin] Found log entries', ['count' => 0])->once();
    Log::shouldReceive('info')->with('[Paladin] No new log entries found')->once();

    ProcessSelfHealingJob::dispatch();

    expect(true)->toBeTrue();
});

it('handles git push failure', function () {
    $issue = [
        'id' => 'issue-push-fail',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'Something went wrong',
        'title' => 'Test Issue',
        'affected_files' => ['app/Models/User.php'],
    ];

    $mockScanner = Mockery::mock(LogScanner::class);
    $mockScanner->shouldReceive('scan')->andReturn([['message' => 'Something went wrong']]);
    $this->app->instance(LogScanner::class, $mockScanner);

    $mockInstaller = Mockery::mock(OpenCodeInstaller::class);
    $mockInstaller->shouldReceive('isInstalled')->andReturn(true);
    $mockInstaller->shouldReceive('getVersion')->andReturn('1.0.0');
    $this->app->instance(OpenCodeInstaller::class, $mockInstaller);

    $this->mockEvaluator->shouldReceive('analyzeIssues')->andReturn([$issue]);
    $this->mockEvaluator->shouldReceive('generatePrompt')->andReturn('Fix it');

    $mockWorktreeManager = Mockery::mock(WorktreeManager::class);
    $mockWorktreeManager->shouldReceive('create')->andReturn(['path' => '/tmp/worktree']);
    $mockWorktreeManager->shouldReceive('exists')->andReturn(true);
    $mockWorktreeManager->shouldReceive('remove')->andReturn(true);
    $this->app->instance(WorktreeManager::class, $mockWorktreeManager);

    $mockWorktreeSetup = Mockery::mock(WorktreeSetup::class);
    $mockWorktreeSetup->shouldReceive('setup')->andReturn(true);
    $this->app->instance(WorktreeSetup::class, $mockWorktreeSetup);

    $mockOpenCodeRunner = Mockery::mock(OpenCodeRunner::class);
    $mockOpenCodeRunner->shouldReceive('run')->andReturn(['success' => true, 'output' => 'Fixed']);
    $this->app->instance(OpenCodeRunner::class, $mockOpenCodeRunner);

    $mockTestRunner = Mockery::mock(TestRunner::class);
    $mockTestRunner->shouldReceive('run')->andReturn(['passed' => true, 'output' => 'Tests passed']);
    $this->app->instance(TestRunner::class, $mockTestRunner);

    $this->mockGitService->shouldReceive('createBranch')->andReturn(true);
    $this->mockGitService->shouldReceive('commit')->andReturn(true);
    $this->mockGitService->shouldReceive('hasRemote')->andReturn(true);
    $this->mockGitService->shouldReceive('push')->andReturn(false);

    ProcessSelfHealingJob::dispatch();

    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-push-fail',
        'status' => 'failed',
    ]);
});

it('handles git branch creation failure', function () {
    config(['paladin.testing.max_fix_attempts' => 1]);
    $issue = [
        'id' => 'issue-branch-fail',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'Something went wrong',
        'title' => 'Test Issue',
        'affected_files' => ['app/Models/User.php'],
    ];

    $mockScanner = Mockery::mock(LogScanner::class);
    $mockScanner->shouldReceive('scan')->andReturn([['message' => 'Something went wrong']]);
    $this->app->instance(LogScanner::class, $mockScanner);

    $mockInstaller = Mockery::mock(OpenCodeInstaller::class);
    $mockInstaller->shouldReceive('isInstalled')->andReturn(true);
    $mockInstaller->shouldReceive('getVersion')->andReturn('1.0.0');
    $this->app->instance(OpenCodeInstaller::class, $mockInstaller);

    $this->mockEvaluator->shouldReceive('analyzeIssues')->andReturn([$issue]);
    $this->mockEvaluator->shouldReceive('generatePrompt')->andReturn('Fix it');

    $mockWorktreeManager = Mockery::mock(WorktreeManager::class);
    $mockWorktreeManager->shouldReceive('create')->andReturn(['path' => '/tmp/worktree']);
    $mockWorktreeManager->shouldReceive('exists')->andReturn(true);
    $mockWorktreeManager->shouldReceive('remove')->andReturn(true);
    $this->app->instance(WorktreeManager::class, $mockWorktreeManager);

    $mockWorktreeSetup = Mockery::mock(WorktreeSetup::class);
    $mockWorktreeSetup->shouldReceive('setup')->andReturn(true);
    $this->app->instance(WorktreeSetup::class, $mockWorktreeSetup);

    $mockOpenCodeRunner = Mockery::mock(OpenCodeRunner::class);
    $mockOpenCodeRunner->shouldReceive('run')->andReturn(['success' => true, 'output' => 'Fixed']);
    $this->app->instance(OpenCodeRunner::class, $mockOpenCodeRunner);

    $mockTestRunner = Mockery::mock(TestRunner::class);
    $mockTestRunner->shouldReceive('run')->andReturn(['passed' => true, 'output' => 'Tests passed']);
    $this->app->instance(TestRunner::class, $mockTestRunner);

    $this->mockGitService->shouldReceive('createBranch')->andReturn(false);

    ProcessSelfHealingJob::dispatch();

    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-branch-fail',
        'status' => 'failed',
    ]);
});

it('handles git commit failure', function () {
    config(['paladin.testing.max_fix_attempts' => 1]);
    $issue = [
        'id' => 'issue-commit-fail',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'Something went wrong',
        'title' => 'Test Issue',
        'affected_files' => ['app/Models/User.php'],
    ];

    $mockScanner = Mockery::mock(LogScanner::class);
    $mockScanner->shouldReceive('scan')->andReturn([['message' => 'Something went wrong']]);
    $this->app->instance(LogScanner::class, $mockScanner);

    $mockInstaller = Mockery::mock(OpenCodeInstaller::class);
    $mockInstaller->shouldReceive('isInstalled')->andReturn(true);
    $mockInstaller->shouldReceive('getVersion')->andReturn('1.0.0');
    $this->app->instance(OpenCodeInstaller::class, $mockInstaller);

    $this->mockEvaluator->shouldReceive('analyzeIssues')->andReturn([$issue]);
    $this->mockEvaluator->shouldReceive('generatePrompt')->andReturn('Fix it');

    $mockWorktreeManager = Mockery::mock(WorktreeManager::class);
    $mockWorktreeManager->shouldReceive('create')->andReturn(['path' => '/tmp/worktree']);
    $mockWorktreeManager->shouldReceive('exists')->andReturn(true);
    $mockWorktreeManager->shouldReceive('remove')->andReturn(true);
    $this->app->instance(WorktreeManager::class, $mockWorktreeManager);

    $mockWorktreeSetup = Mockery::mock(WorktreeSetup::class);
    $mockWorktreeSetup->shouldReceive('setup')->andReturn(true);
    $this->app->instance(WorktreeSetup::class, $mockWorktreeSetup);

    $mockOpenCodeRunner = Mockery::mock(OpenCodeRunner::class);
    $mockOpenCodeRunner->shouldReceive('run')->andReturn(['success' => true, 'output' => 'Fixed']);
    $this->app->instance(OpenCodeRunner::class, $mockOpenCodeRunner);

    $mockTestRunner = Mockery::mock(TestRunner::class);
    $mockTestRunner->shouldReceive('run')->andReturn(['passed' => true, 'output' => 'Tests passed']);
    $this->app->instance(TestRunner::class, $mockTestRunner);

    $this->mockGitService->shouldReceive('createBranch')->andReturn(true);
    $this->mockGitService->shouldReceive('commit')->andReturn(false);

    ProcessSelfHealingJob::dispatch();

    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-commit-fail',
        'status' => 'failed',
    ]);
});
