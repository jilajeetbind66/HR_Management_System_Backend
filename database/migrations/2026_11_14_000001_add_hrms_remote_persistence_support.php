<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_assignments', function (Blueprint $table) {
            $table->text('review_note')->nullable();
            $table->string('returned_by')->nullable();
        });

        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->after('created_by')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('sla_due_at')->nullable()->after('sla_hours_left');
        });

        Schema::create('recruitment_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('department');
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->unsignedSmallInteger('vacancies')->default(1);
            $table->string('status', 20)->default('Open');
            $table->string('experience')->nullable();
            $table->timestamps();
        });

        Schema::create('recruitment_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('recruitment_jobs')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->unique()->constrained('employees')->nullOnDelete();
            $table->string('first_name');
            $table->string('last_name')->default('');
            $table->string('email');
            $table->string('phone', 30)->nullable();
            $table->string('stage', 20)->default('Applied');
            $table->decimal('rating', 3, 1)->default(0);
            $table->string('current_ctc')->nullable();
            $table->string('expected_ctc')->nullable();
            $table->string('notice_period')->nullable();
            $table->timestamps();

            $table->index(['job_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recruitment_candidates');
        Schema::dropIfExists('recruitment_jobs');

        Schema::table('project_assignments', function (Blueprint $table) {
            $table->dropColumn(['review_note', 'returned_by']);
        });

        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            $table->dropColumn('sla_due_at');
            $table->dropForeign(['created_by_user_id']);
            $table->dropColumn('created_by_user_id');
        });
    }
};
