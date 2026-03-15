<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('healing_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('issue_id')->index();
            $table->string('issue_type');
            $table->string('severity');
            $table->text('message');
            $table->text('stack_trace')->nullable();
            $table->json('affected_files')->nullable();
            $table->string('worktree_path')->nullable();
            $table->string('branch_name')->nullable();
            $table->integer('attempt_number')->default(1);
            $table->string('status')->default('pending'); // pending, in_progress, fixed, failed, skipped
            $table->text('opencode_prompt')->nullable();
            $table->text('opencode_output')->nullable();
            $table->text('test_output')->nullable();
            $table->string('pr_url')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->unique(['issue_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('healing_attempts');
    }
};
