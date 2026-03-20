<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Kekser\LaravelPaladin\Ai\EvaluatorFactory;
use Kekser\LaravelPaladin\Contracts\IssueEvaluator;
use Kekser\LaravelPaladin\Models\HealingAttempt;
use Kekser\LaravelPaladin\Pr\PullRequestManager;
use Kekser\LaravelPaladin\Services\FileBoundaryValidator;
use Kekser\LaravelPaladin\Services\GitService;
use Kekser\LaravelPaladin\Services\IssuePrioritizer;
use Kekser\LaravelPaladin\Services\OpenCodeRunner;
use Kekser\LaravelPaladin\Services\SelfHealingOrchestrator;
use Kekser\LaravelPaladin\Services\TemplateGenerator;
use Kekser\LaravelPaladin\Services\TestRunner;
use Kekser\LaravelPaladin\Services\WorktreeManager;
use Kekser\LaravelPaladin\Services\WorktreeSetup;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->prioritizer = new IssuePrioritizer;
    $this->templateGenerator = new TemplateGenerator;
    $this->fileBoundaryValidator = Mockery::mock(FileBoundaryValidator::class);
    $this->worktreeManager = Mockery::mock(WorktreeManager::class);
    $this->worktreeSetup = Mockery::mock(WorktreeSetup::class);
    $this->openCodeRunner = Mockery::mock(OpenCodeRunner::class);
    $this->testRunner = Mockery::mock(TestRunner::class);
    $this->gitService = Mockery::mock(GitService::class);
    $this->pullRequestManager = Mockery::mock(PullRequestManager::class);

    $this->orchestrator = new SelfHealingOrchestrator(
        $this->prioritizer,
        $this->templateGenerator,
        $this->fileBoundaryValidator,
        $this->worktreeManager,
        $this->worktreeSetup,
        $this->openCodeRunner,
        $this->testRunner,
        $this->gitService,
        $this->pullRequestManager
    );

    // Mock EvaluatorFactory
    $this->mockEvaluator = Mockery::mock(IssueEvaluator::class);
    $this->mockEvaluatorFactory = Mockery::mock(EvaluatorFactory::class);
    $this->mockEvaluatorFactory->shouldReceive('create')->andReturn($this->mockEvaluator);
    $this->app->instance(EvaluatorFactory::class, $this->mockEvaluatorFactory);

    config([
        'paladin.testing.max_fix_attempts' => 3,
        'paladin.testing.skip_tests' => false,
        'paladin.worktree.cleanup_after_success' => true,
        'paladin.git.branch_prefix' => 'paladin/fix',
        'paladin.git.commit_message_template' => 'Fix {issue_title}',
        'paladin.git.pr_title_template' => 'Fix {issue_title}',
        'paladin.git.pr_body_template' => 'Fixes {issue_description}',
    ]);
});

test('it processes fixable issue successfully', function () {
    $issue = [
        'id' => 'issue-1',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'Something went wrong',
        'title' => 'Test Issue',
        'affected_files' => ['app/Models/User.php'],
    ];

    // Mock file boundary validation
    $this->fileBoundaryValidator->shouldReceive('analyzeIssue')
        ->with(['app/Models/User.php'])
        ->andReturn([
            'is_fixable' => true,
            'reason' => '',
            'internal_files' => ['app/Models/User.php'],
            'external_files' => [],
        ]);

    // Mock worktree creation
    $this->worktreeManager->shouldReceive('create')
        ->with('issue-1')
        ->andReturn(['path' => '/tmp/worktree']);

    // Mock worktree setup
    $this->worktreeSetup->shouldReceive('setup')
        ->with('/tmp/worktree')
        ->andReturn(true);

    // Mock evaluator for prompt generation
    $this->mockEvaluator->shouldReceive('generatePrompt')
        ->with($issue, null)
        ->andReturn('Fix this issue');

    // Mock OpenCode runner
    $this->openCodeRunner->shouldReceive('run')
        ->with('Fix this issue', '/tmp/worktree')
        ->andReturn(['success' => true, 'output' => 'Fixed']);

    // Mock test runner
    $this->testRunner->shouldReceive('run')
        ->with('/tmp/worktree')
        ->andReturn(['passed' => true, 'output' => 'Tests passed']);

    // Mock git operations
    $this->gitService->shouldReceive('createBranch')
        ->with('/tmp/worktree', 'paladin/fix-issue-1')
        ->andReturn(true);

    $this->gitService->shouldReceive('commit')
        ->with('/tmp/worktree', 'Fix Test Issue')
        ->andReturn(true);

    $this->gitService->shouldReceive('hasRemote')
        ->with('/tmp/worktree')
        ->andReturn(true);

    $this->gitService->shouldReceive('push')
        ->with('/tmp/worktree', 'paladin/fix-issue-1')
        ->andReturn(true);

    // Mock PR creation
    $this->pullRequestManager->shouldReceive('createPullRequest')
        ->with('paladin/fix-issue-1', 'Fix Test Issue', Mockery::any())
        ->andReturn('https://github.com/test/pull/1');

    // Mock worktree cleanup
    $this->worktreeManager->shouldReceive('remove')
        ->with('/tmp/worktree')
        ->andReturn(true);

    $this->orchestrator->processIssues([$issue]);

    // Assert healing attempt was created and marked as fixed
    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-1',
        'status' => 'fixed',
        'pr_url' => 'https://github.com/test/pull/1',
    ]);
});

test('it skips issues outside project boundaries', function () {
    $issue = [
        'id' => 'issue-external',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'Something in vendor went wrong',
        'title' => 'External Issue',
        'affected_files' => ['vendor/laravel/framework/src/Illuminate/Foundation/Application.php'],
    ];

    $this->fileBoundaryValidator->shouldReceive('analyzeIssue')
        ->with(['vendor/laravel/framework/src/Illuminate/Foundation/Application.php'])
        ->andReturn([
            'is_fixable' => false,
            'reason' => 'All affected files are outside project boundaries',
            'internal_files' => [],
            'external_files' => ['vendor/laravel/framework/src/Illuminate/Foundation/Application.php'],
        ]);

    $this->orchestrator->processIssues([$issue]);

    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-external',
        'status' => 'skipped',
        'error_message' => 'All affected files are outside project boundaries',
    ]);
});

test('it retries failed fix attempts up to max attempts', function () {
    config(['paladin.testing.max_fix_attempts' => 2]);

    $issue = [
        'id' => 'issue-retry',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'Something went wrong',
        'title' => 'Test Issue',
        'affected_files' => ['app/Models/User.php'],
    ];

    // File boundary validation is only done once per issue (before retries)
    $this->fileBoundaryValidator->shouldReceive('analyzeIssue')
        ->once()
        ->andReturn([
            'is_fixable' => true,
            'reason' => '',
            'internal_files' => ['app/Models/User.php'],
            'external_files' => [],
        ]);

    $this->worktreeManager->shouldReceive('create')
        ->twice()
        ->andReturn(['path' => '/tmp/worktree']);

    $this->worktreeSetup->shouldReceive('setup')
        ->twice()
        ->andReturn(true);

    $this->mockEvaluator->shouldReceive('generatePrompt')
        ->twice()
        ->andReturn('Fix this issue');

    // Both attempts fail
    $this->openCodeRunner->shouldReceive('run')
        ->twice()
        ->andReturn(['success' => false, 'output' => 'Failed']);

    // Worktree cleanup on failure
    $this->worktreeManager->shouldReceive('exists')
        ->andReturn(true);

    $this->orchestrator->processIssues([$issue]);

    // Assert two healing attempts were created, both failed
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

test('it filters issues by specific issue ids', function () {
    $issue1 = [
        'id' => 'issue-1',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'First issue',
        'affected_files' => ['app/Models/User.php'],
    ];

    $issue2 = [
        'id' => 'issue-2',
        'type' => 'error',
        'severity' => 'medium',
        'message' => 'Second issue',
        'affected_files' => ['app/Models/Post.php'],
    ];

    // Only issue-1 should be processed
    $this->fileBoundaryValidator->shouldReceive('analyzeIssue')
        ->once()
        ->andReturn([
            'is_fixable' => false,
            'reason' => 'Skipped for test',
            'internal_files' => [],
            'external_files' => [],
        ]);

    $this->orchestrator->processIssues([$issue1, $issue2], ['issue-1']);

    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-1',
    ]);

    $this->assertDatabaseMissing('healing_attempts', [
        'issue_id' => 'issue-2',
    ]);
});

test('it skips tests when configured', function () {
    config(['paladin.testing.skip_tests' => true]);

    $issue = [
        'id' => 'issue-skip-tests',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'Something went wrong',
        'title' => 'Test Issue',
        'affected_files' => ['app/Models/User.php'],
    ];

    $this->fileBoundaryValidator->shouldReceive('analyzeIssue')
        ->andReturn([
            'is_fixable' => true,
            'reason' => '',
            'internal_files' => ['app/Models/User.php'],
            'external_files' => [],
        ]);

    $this->worktreeManager->shouldReceive('create')
        ->andReturn(['path' => '/tmp/worktree']);

    $this->worktreeSetup->shouldReceive('setup')
        ->andReturn(true);

    $this->mockEvaluator->shouldReceive('generatePrompt')
        ->andReturn('Fix this issue');

    $this->openCodeRunner->shouldReceive('run')
        ->andReturn(['success' => true, 'output' => 'Fixed']);

    // No test runner should be called

    $this->gitService->shouldReceive('createBranch')
        ->andReturn(true);

    $this->gitService->shouldReceive('commit')
        ->andReturn(true);

    $this->gitService->shouldReceive('hasRemote')
        ->andReturn(false);

    $this->worktreeManager->shouldReceive('remove')
        ->andReturn(true);

    $this->orchestrator->processIssues([$issue]);

    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-skip-tests',
        'status' => 'fixed',
        'test_output' => 'Tests skipped (PALADIN_SKIP_TESTS=true)',
    ]);
});

test('it handles worktree setup failure', function () {
    $issue = [
        'id' => 'issue-setup-fail',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'Something went wrong',
        'title' => 'Test Issue',
        'affected_files' => ['app/Models/User.php'],
    ];

    $this->fileBoundaryValidator->shouldReceive('analyzeIssue')
        ->andReturn([
            'is_fixable' => true,
            'reason' => '',
            'internal_files' => ['app/Models/User.php'],
            'external_files' => [],
        ]);

    $this->worktreeManager->shouldReceive('create')
        ->andReturn(['path' => '/tmp/worktree']);

    $this->worktreeSetup->shouldReceive('setup')
        ->with('/tmp/worktree')
        ->andReturn(false);

    $this->worktreeManager->shouldReceive('exists')
        ->andReturn(true);

    $this->worktreeManager->shouldReceive('remove')
        ->with('/tmp/worktree')
        ->andReturn(true);

    $this->orchestrator->processIssues([$issue]);

    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-setup-fail',
        'status' => 'failed',
        'error_message' => 'Worktree setup failed',
    ]);
});

test('it handles test failure after fix', function () {
    $issue = [
        'id' => 'issue-test-fail',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'Something went wrong',
        'title' => 'Test Issue',
        'affected_files' => ['app/Models/User.php'],
    ];

    $this->fileBoundaryValidator->shouldReceive('analyzeIssue')
        ->andReturn([
            'is_fixable' => true,
            'reason' => '',
            'internal_files' => ['app/Models/User.php'],
            'external_files' => [],
        ]);

    $this->worktreeManager->shouldReceive('create')
        ->andReturn(['path' => '/tmp/worktree']);

    $this->worktreeSetup->shouldReceive('setup')
        ->andReturn(true);

    $this->mockEvaluator->shouldReceive('generatePrompt')
        ->andReturn('Fix this issue');

    $this->openCodeRunner->shouldReceive('run')
        ->andReturn(['success' => true, 'output' => 'Fixed']);

    // Tests fail
    $this->testRunner->shouldReceive('run')
        ->with('/tmp/worktree')
        ->andReturn(['passed' => false, 'output' => 'Tests failed']);

    $this->worktreeManager->shouldReceive('exists')
        ->andReturn(true);

    $this->orchestrator->processIssues([$issue]);

    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-test-fail',
        'status' => 'failed',
        'test_output' => 'Tests failed',
    ]);
});

test('it handles git operations failure', function () {
    config(['paladin.testing.max_fix_attempts' => 1]);

    $issue = [
        'id' => 'issue-branch-fail',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'Something went wrong',
        'title' => 'Test Issue',
        'affected_files' => ['app/Models/User.php'],
    ];

    $this->fileBoundaryValidator->shouldReceive('analyzeIssue')
        ->andReturn([
            'is_fixable' => true,
            'reason' => '',
            'internal_files' => ['app/Models/User.php'],
            'external_files' => [],
        ]);

    $this->worktreeManager->shouldReceive('create')
        ->andReturn(['path' => '/tmp/worktree']);

    $this->worktreeSetup->shouldReceive('setup')
        ->andReturn(true);

    $this->mockEvaluator->shouldReceive('generatePrompt')
        ->andReturn('Fix this issue');

    $this->openCodeRunner->shouldReceive('run')
        ->andReturn(['success' => true, 'output' => 'Fixed']);

    $this->testRunner->shouldReceive('run')
        ->andReturn(['passed' => true, 'output' => 'Tests passed']);

    // Branch creation fails
    $this->gitService->shouldReceive('createBranch')
        ->andReturn(false);

    $this->worktreeManager->shouldReceive('exists')
        ->andReturn(true);

    $this->orchestrator->processIssues([$issue]);

    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-branch-fail',
        'status' => 'failed',
        'error_message' => 'Failed to create branch',
    ]);
});

test('it marks as fixed locally when no remote configured', function () {
    $issue = [
        'id' => 'issue-local',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'Something went wrong',
        'title' => 'Test Issue',
        'affected_files' => ['app/Models/User.php'],
    ];

    $this->fileBoundaryValidator->shouldReceive('analyzeIssue')
        ->andReturn([
            'is_fixable' => true,
            'reason' => '',
            'internal_files' => ['app/Models/User.php'],
            'external_files' => [],
        ]);

    $this->worktreeManager->shouldReceive('create')
        ->andReturn(['path' => '/tmp/worktree']);

    $this->worktreeSetup->shouldReceive('setup')
        ->andReturn(true);

    $this->mockEvaluator->shouldReceive('generatePrompt')
        ->andReturn('Fix this issue');

    $this->openCodeRunner->shouldReceive('run')
        ->andReturn(['success' => true, 'output' => 'Fixed']);

    $this->testRunner->shouldReceive('run')
        ->andReturn(['passed' => true, 'output' => 'Tests passed']);

    $this->gitService->shouldReceive('createBranch')
        ->andReturn(true);

    $this->gitService->shouldReceive('commit')
        ->andReturn(true);

    // No remote configured
    $this->gitService->shouldReceive('hasRemote')
        ->andReturn(false);

    $this->worktreeManager->shouldReceive('remove')
        ->andReturn(true);

    $this->orchestrator->processIssues([$issue]);

    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-local',
        'status' => 'fixed',
        'pr_url' => null,
    ]);
});

test('it passes previous test output on retry attempts', function () {
    config(['paladin.testing.max_fix_attempts' => 2]);

    $issue = [
        'id' => 'issue-retry-test',
        'type' => 'error',
        'severity' => 'high',
        'message' => 'Something went wrong',
        'title' => 'Test Issue',
        'affected_files' => ['app/Models/User.php'],
    ];

    // File boundary validation is only done once per issue (before retries)
    $this->fileBoundaryValidator->shouldReceive('analyzeIssue')
        ->once()
        ->andReturn([
            'is_fixable' => true,
            'reason' => '',
            'internal_files' => ['app/Models/User.php'],
            'external_files' => [],
        ]);

    $this->worktreeManager->shouldReceive('create')
        ->twice()
        ->andReturn(['path' => '/tmp/worktree']);

    $this->worktreeSetup->shouldReceive('setup')
        ->twice()
        ->andReturn(true);

    // First attempt - generatePrompt with no test output
    $this->mockEvaluator->shouldReceive('generatePrompt')
        ->once()
        ->with($issue, null)
        ->andReturn('Fix this issue');

    // Second attempt - generatePrompt with previous test output
    $this->mockEvaluator->shouldReceive('generatePrompt')
        ->once()
        ->with($issue, 'First attempt failed')
        ->andReturn('Fix this issue with previous output');

    // First attempt fails
    $this->openCodeRunner->shouldReceive('run')
        ->once()
        ->andReturn(['success' => false, 'output' => 'Failed']);

    // Second attempt succeeds
    $this->openCodeRunner->shouldReceive('run')
        ->once()
        ->andReturn(['success' => true, 'output' => 'Fixed']);

    $this->testRunner->shouldReceive('run')
        ->once()
        ->andReturn(['passed' => true, 'output' => 'Tests passed']);

    $this->gitService->shouldReceive('createBranch')
        ->andReturn(true);

    $this->gitService->shouldReceive('commit')
        ->andReturn(true);

    $this->gitService->shouldReceive('hasRemote')
        ->andReturn(false);

    $this->worktreeManager->shouldReceive('remove')
        ->andReturn(true);

    $this->worktreeManager->shouldReceive('exists')
        ->andReturn(true);

    // Create first attempt record manually with test_output
    HealingAttempt::create([
        'issue_id' => 'issue-retry-test',
        'issue_type' => 'error',
        'severity' => 'high',
        'message' => 'Something went wrong',
        'attempt_number' => 1,
        'status' => 'failed',
        'test_output' => 'First attempt failed',
    ]);

    $this->orchestrator->processIssues([$issue]);

    // Should have created a second attempt
    $this->assertDatabaseHas('healing_attempts', [
        'issue_id' => 'issue-retry-test',
        'attempt_number' => 2,
    ]);
});
