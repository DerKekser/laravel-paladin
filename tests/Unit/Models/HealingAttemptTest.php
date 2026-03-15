<?php

namespace Kekser\LaravelPaladin\Tests\Unit\Models;

use Kekser\LaravelPaladin\Models\HealingAttempt;
use Kekser\LaravelPaladin\Tests\TestCase;

class HealingAttemptTest extends TestCase
{
    /** @test */
    public function it_can_create_a_healing_attempt()
    {
        $attempt = HealingAttempt::create([
            'issue_id' => 'test-123',
            'issue_type' => 'runtime_error',
            'severity' => 'high',
            'message' => 'Test error message',
            'stack_trace' => 'Test stack trace',
            'affected_files' => ['app/Test.php'],
            'attempt_number' => 1,
        ]);

        $this->assertDatabaseHas('healing_attempts', [
            'issue_id' => 'test-123',
            'issue_type' => 'runtime_error',
            'severity' => 'high',
            'status' => 'pending',
        ]);

        $this->assertEquals('test-123', $attempt->issue_id);
        $this->assertEquals('pending', $attempt->status);
        $this->assertEquals(['app/Test.php'], $attempt->affected_files);
    }

    /** @test */
    public function it_casts_affected_files_to_array()
    {
        $attempt = HealingAttempt::create([
            'issue_id' => 'test-456',
            'issue_type' => 'syntax_error',
            'severity' => 'critical',
            'message' => 'Syntax error',
            'affected_files' => ['file1.php', 'file2.php', 'file3.php'],
        ]);

        $this->assertIsArray($attempt->affected_files);
        $this->assertCount(3, $attempt->affected_files);
        $this->assertEquals(['file1.php', 'file2.php', 'file3.php'], $attempt->affected_files);

        // Reload from database to ensure casting works
        $reloaded = HealingAttempt::find($attempt->id);
        $this->assertIsArray($reloaded->affected_files);
        $this->assertEquals(['file1.php', 'file2.php', 'file3.php'], $reloaded->affected_files);
    }

    /** @test */
    public function it_can_mark_attempt_as_in_progress()
    {
        $attempt = HealingAttempt::create([
            'issue_id' => 'test-789',
            'issue_type' => 'runtime_error',
            'severity' => 'medium',
            'message' => 'Test message',
        ]);

        $this->assertEquals('pending', $attempt->status);

        $attempt->markAsInProgress();

        $this->assertEquals('in_progress', $attempt->fresh()->status);
        $this->assertDatabaseHas('healing_attempts', [
            'id' => $attempt->id,
            'status' => 'in_progress',
        ]);
    }

    /** @test */
    public function it_can_mark_attempt_as_fixed()
    {
        $attempt = HealingAttempt::create([
            'issue_id' => 'test-101',
            'issue_type' => 'runtime_error',
            'severity' => 'high',
            'message' => 'Test message',
            'status' => 'in_progress',
        ]);

        $prUrl = 'https://github.com/test/repo/pull/42';
        $attempt->markAsFixed($prUrl);

        $this->assertEquals('fixed', $attempt->fresh()->status);
        $this->assertEquals($prUrl, $attempt->fresh()->pr_url);
        $this->assertDatabaseHas('healing_attempts', [
            'id' => $attempt->id,
            'status' => 'fixed',
            'pr_url' => $prUrl,
        ]);
    }

    /** @test */
    public function it_can_mark_attempt_as_fixed_without_pr_url()
    {
        $attempt = HealingAttempt::create([
            'issue_id' => 'test-102',
            'issue_type' => 'runtime_error',
            'severity' => 'high',
            'message' => 'Test message',
            'status' => 'in_progress',
        ]);

        $attempt->markAsFixed();

        $this->assertEquals('fixed', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->pr_url);
    }

    /** @test */
    public function it_can_mark_attempt_as_failed()
    {
        $attempt = HealingAttempt::create([
            'issue_id' => 'test-103',
            'issue_type' => 'runtime_error',
            'severity' => 'high',
            'message' => 'Test message',
            'status' => 'in_progress',
        ]);

        $errorMessage = 'Tests failed after fix';
        $attempt->markAsFailed($errorMessage);

        $this->assertEquals('failed', $attempt->fresh()->status);
        $this->assertEquals($errorMessage, $attempt->fresh()->error_message);
        $this->assertDatabaseHas('healing_attempts', [
            'id' => $attempt->id,
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }

    /** @test */
    public function it_can_mark_attempt_as_failed_without_error_message()
    {
        $attempt = HealingAttempt::create([
            'issue_id' => 'test-104',
            'issue_type' => 'runtime_error',
            'severity' => 'high',
            'message' => 'Test message',
            'status' => 'in_progress',
        ]);

        $attempt->markAsFailed();

        $this->assertEquals('failed', $attempt->fresh()->status);
        $this->assertNull($attempt->fresh()->error_message);
    }

    /** @test */
    public function it_can_filter_by_pending_status()
    {
        HealingAttempt::create(['issue_id' => 'test-1', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'pending']);
        HealingAttempt::create(['issue_id' => 'test-2', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'in_progress']);
        HealingAttempt::create(['issue_id' => 'test-3', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'pending']);
        HealingAttempt::create(['issue_id' => 'test-4', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'fixed']);

        $pending = HealingAttempt::pending()->get();

        $this->assertCount(2, $pending);
        $this->assertTrue($pending->every(fn ($a) => $a->status === 'pending'));
    }

    /** @test */
    public function it_can_filter_by_in_progress_status()
    {
        HealingAttempt::create(['issue_id' => 'test-1', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'pending']);
        HealingAttempt::create(['issue_id' => 'test-2', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'in_progress']);
        HealingAttempt::create(['issue_id' => 'test-3', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'in_progress']);

        $inProgress = HealingAttempt::inProgress()->get();

        $this->assertCount(2, $inProgress);
        $this->assertTrue($inProgress->every(fn ($a) => $a->status === 'in_progress'));
    }

    /** @test */
    public function it_can_filter_by_fixed_status()
    {
        HealingAttempt::create(['issue_id' => 'test-1', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'pending']);
        HealingAttempt::create(['issue_id' => 'test-2', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'fixed']);
        HealingAttempt::create(['issue_id' => 'test-3', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'fixed']);
        HealingAttempt::create(['issue_id' => 'test-4', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'failed']);

        $fixed = HealingAttempt::fixed()->get();

        $this->assertCount(2, $fixed);
        $this->assertTrue($fixed->every(fn ($a) => $a->status === 'fixed'));
    }

    /** @test */
    public function it_can_filter_by_failed_status()
    {
        HealingAttempt::create(['issue_id' => 'test-1', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'failed']);
        HealingAttempt::create(['issue_id' => 'test-2', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'fixed']);
        HealingAttempt::create(['issue_id' => 'test-3', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'failed']);

        $failed = HealingAttempt::failed()->get();

        $this->assertCount(2, $failed);
        $this->assertTrue($failed->every(fn ($a) => $a->status === 'failed'));
    }

    /** @test */
    public function it_can_filter_by_issue_id()
    {
        HealingAttempt::create(['issue_id' => 'issue-123', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'attempt_number' => 1]);
        HealingAttempt::create(['issue_id' => 'issue-123', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'attempt_number' => 2]);
        HealingAttempt::create(['issue_id' => 'issue-456', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'attempt_number' => 1]);
        HealingAttempt::create(['issue_id' => 'issue-123', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'attempt_number' => 3]);

        $attempts = HealingAttempt::byIssueId('issue-123')->get();

        $this->assertCount(3, $attempts);
        $this->assertTrue($attempts->every(fn ($a) => $a->issue_id === 'issue-123'));
    }

    /** @test */
    public function it_can_chain_scopes()
    {
        HealingAttempt::create(['issue_id' => 'issue-100', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'pending', 'attempt_number' => 1]);
        HealingAttempt::create(['issue_id' => 'issue-100', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'fixed', 'attempt_number' => 2]);
        HealingAttempt::create(['issue_id' => 'issue-100', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'failed', 'attempt_number' => 3]);
        HealingAttempt::create(['issue_id' => 'issue-200', 'issue_type' => 'error', 'severity' => 'high', 'message' => 'Test', 'status' => 'fixed']);

        $attempts = HealingAttempt::byIssueId('issue-100')->fixed()->get();

        $this->assertCount(1, $attempts);
        $this->assertEquals('issue-100', $attempts->first()->issue_id);
        $this->assertEquals('fixed', $attempts->first()->status);
    }

    /** @test */
    public function it_has_timestamps()
    {
        $attempt = HealingAttempt::create([
            'issue_id' => 'test-timestamps',
            'issue_type' => 'runtime_error',
            'severity' => 'high',
            'message' => 'Test message',
        ]);

        $this->assertNotNull($attempt->created_at);
        $this->assertNotNull($attempt->updated_at);
        $this->assertEquals($attempt->created_at->timestamp, $attempt->updated_at->timestamp, '', 1);
    }

    /** @test */
    public function it_updates_timestamp_on_status_change()
    {
        $attempt = HealingAttempt::create([
            'issue_id' => 'test-update',
            'issue_type' => 'runtime_error',
            'severity' => 'high',
            'message' => 'Test message',
        ]);

        $originalUpdatedAt = $attempt->updated_at;

        // Wait a moment to ensure timestamp difference
        sleep(1);

        $attempt->markAsInProgress();

        $this->assertNotEquals($originalUpdatedAt->timestamp, $attempt->fresh()->updated_at->timestamp);
    }

    /** @test */
    public function it_defaults_status_to_pending()
    {
        $attempt = HealingAttempt::create([
            'issue_id' => 'test-default',
            'issue_type' => 'runtime_error',
            'severity' => 'high',
            'message' => 'Test message',
        ]);

        $this->assertEquals('pending', $attempt->status);
    }

    /** @test */
    public function it_defaults_attempt_number_to_one()
    {
        $attempt = HealingAttempt::create([
            'issue_id' => 'test-attempt-default',
            'issue_type' => 'runtime_error',
            'severity' => 'high',
            'message' => 'Test message',
        ]);

        $this->assertEquals(1, $attempt->attempt_number);
    }
}
