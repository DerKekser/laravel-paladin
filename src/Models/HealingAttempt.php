<?php

namespace Kekser\LaravelPaladin\Models;

use Illuminate\Database\Eloquent\Model;

class HealingAttempt extends Model
{
    protected $fillable = [
        'issue_id',
        'issue_type',
        'severity',
        'message',
        'stack_trace',
        'affected_files',
        'worktree_path',
        'branch_name',
        'attempt_number',
        'status',
        'opencode_prompt',
        'opencode_output',
        'test_output',
        'pr_url',
        'error_message',
    ];

    protected $casts = [
        'affected_files' => 'array',
    ];

    public function markAsInProgress(): void
    {
        $this->update(['status' => 'in_progress']);
    }

    public function markAsFixed(?string $prUrl = null): void
    {
        $this->update([
            'status' => 'fixed',
            'pr_url' => $prUrl,
        ]);
    }

    public function markAsFailed(?string $errorMessage = null): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeInProgress($query)
    {
        return $query->where('status', 'in_progress');
    }

    public function scopeFixed($query)
    {
        return $query->where('status', 'fixed');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeByIssueId($query, string $issueId)
    {
        return $query->where('issue_id', $issueId);
    }
}
