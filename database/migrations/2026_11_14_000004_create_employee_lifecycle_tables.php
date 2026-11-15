<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_lifecycle_cases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('case_type', 20);
            $table->date('notice_date')->nullable();
            $table->date('effective_date');
            $table->date('target_completion_date')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'case_type', 'status']);
        });

        Schema::create('employee_lifecycle_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lifecycle_case_id')
                ->constrained('employee_lifecycle_cases')
                ->cascadeOnDelete();
            $table->string('department', 50);
            $table->string('title');
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('notes')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['lifecycle_case_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_lifecycle_tasks');
        Schema::dropIfExists('employee_lifecycle_cases');
    }
};
