<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Penalty Policies (Section 9 & 10 Safeguards)
        Schema::create('project_penalty_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('grace_days')->default(2);
            $table->string('penalty_type', 30)->default('non_monetary'); // non_monetary default safeguard
            $table->json('rule_config')->nullable();
            $table->date('active_from')->nullable();
            $table->date('active_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Projects (Section 9)
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('project_code', 30)->unique();
            $table->string('name');
            $table->text('description');
            $table->text('deliverable');
            $table->string('priority', 20)->default('Medium'); // Low, Medium, High, Urgent
            $table->string('status', 30)->default('assigned'); // draft, assigned, in_progress, submitted, under_review, completed, overdue, cancelled
            $table->string('created_by');
            $table->timestamps();
        });

        // Project Assignments (Single developer assignment in v1)
        Schema::create('project_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('assigned_by');
            $table->date('start_date');
            $table->date('due_date'); // Immutable original baseline planned end date
            $table->date('active_due_date'); // Active due date (changes only on approved extension)
            $table->timestamp('actual_completed_at')->nullable();
            $table->unsignedTinyInteger('progress_percent')->default(0); // 0 - 100
            $table->string('status', 30)->default('in_progress'); // in_progress, submitted, under_review, completed, overdue
            $table->timestamp('accepted_at')->nullable();
            $table->string('accepted_by')->nullable();
            $table->text('submission_note')->nullable();
            $table->string('deliverable_link')->nullable();
            $table->unsignedInteger('version')->default(1); // Section 7.6 concurrency token
            $table->timestamps();
        });

        // Progress Updates
        Schema::create('project_progress_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('project_assignments')->cascadeOnDelete();
            $table->string('updated_by');
            $table->unsignedTinyInteger('progress_percent');
            $table->text('note');
            $table->string('attachment_id')->nullable();
            $table->timestamps();
        });

        // Extension Requests (Separate history preserving initial due date)
        Schema::create('project_extension_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('project_assignments')->cascadeOnDelete();
            $table->string('requested_by');
            $table->date('old_due_date');
            $table->date('requested_due_date');
            $table->text('reason');
            $table->string('status', 20)->default('pending'); // pending, approved, rejected
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
        });

        // Penalties (Policy-driven record; strictly non-monetary default)
        Schema::create('project_penalties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained('project_assignments')->cascadeOnDelete();
            $table->foreignId('policy_id')->nullable()->constrained('project_penalty_policies')->nullOnDelete();
            $table->unsignedInteger('overdue_days_at_assessment')->default(0);
            $table->string('penalty_type', 30)->default('non_monetary');
            $table->decimal('amount', 14, 2)->nullable(); // DECIMAL(14,2) Section 9 rule
            $table->text('reason');
            $table->string('status', 20)->default('approved'); // approved, waived
            $table->string('approved_by')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('waived_at')->nullable();
            $table->string('waived_by')->nullable();
            $table->text('waiver_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_penalties');
        Schema::dropIfExists('project_extension_requests');
        Schema::dropIfExists('project_progress_updates');
        Schema::dropIfExists('project_assignments');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('project_penalty_policies');
    }
};
