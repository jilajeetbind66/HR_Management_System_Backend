<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Attendance Punches & Ledger
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('date');
            $table->string('punch_in', 15)->nullable();
            $table->string('punch_out', 15)->nullable();
            $table->string('status', 20)->default('Present'); // Present, Absent, Half Day, Late
            $table->decimal('work_hours', 5, 2)->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->string('location')->nullable();
            $table->unique(['employee_id', 'date']);
            $table->timestamps();
        });

        // Attendance Regularization (Section 2 & 7)
        Schema::create('attendance_regularization_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->date('date');
            $table->string('punch_in_requested', 15);
            $table->string('punch_out_requested', 15);
            $table->text('reason');
            $table->string('status', 20)->default('pending'); // pending, approved, rejected
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        // Leave Balances
        Schema::create('leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('employees')->cascadeOnDelete();
            $table->decimal('cl', 5, 2)->default(12.00); // Casual Leave
            $table->decimal('sl', 5, 2)->default(12.00); // Sick Leave
            $table->decimal('pl', 5, 2)->default(15.00); // Privilege Leave
            $table->decimal('comp_off', 5, 2)->default(0.00); // Compensatory Off
            $table->timestamps();
        });

        // Leave Applications & Approvals
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('leave_type', 50);
            $table->date('from_date');
            $table->date('to_date');
            $table->decimal('days', 5, 2)->default(1.00);
            $table->text('reason');
            $table->string('status', 20)->default('pending'); // pending, approved, rejected, cancelled
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('leave_balances');
        Schema::dropIfExists('attendance_regularization_requests');
        Schema::dropIfExists('attendance_records');
    }
};
