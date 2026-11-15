<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Employees master
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('employee_code', 20)->unique();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('email')->unique();
            $table->string('phone', 20)->nullable();
            $table->string('gender', 10)->default('Other');
            $table->date('date_of_birth')->nullable();
            $table->string('avatar')->nullable();
            $table->string('designation');
            $table->string('department');
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('reporting_manager_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->date('date_of_joining');
            $table->string('employment_type')->default('Full-Time Permanent');
            $table->string('probation_status')->default('Confirmed');
            $table->string('status', 20)->default('active'); // active, inactive, terminated, on_notice
            $table->unsignedInteger('version')->default(1); // Concurrency token Section 7.6
            $table->timestamps();
        });

        // Add employee_id to users (Section 7.1 requirement)
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('employee_id')->nullable()->unique()->constrained('employees')->nullOnDelete();
            $table->string('role', 30)->default('employee');
        });

        // Employee Statutory Details (Section 7.4)
        Schema::create('employee_statutory_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->unique()->constrained('employees')->cascadeOnDelete();
            $table->string('pan', 10)->nullable();
            $table->string('uan', 12)->nullable();
            $table->boolean('pf_applicable')->default(true);
            $table->string('pf_account_no')->nullable();
            $table->boolean('esi_applicable')->default(false);
            $table->string('esi_number')->nullable();
            $table->string('pt_state')->default('Uttar Pradesh');
            $table->string('tax_regime', 10)->default('New'); // New, Old
            $table->decimal('ctc_annual', 14, 2)->default(0);
            $table->decimal('gross_monthly', 14, 2)->default(0);
            $table->decimal('basic_monthly', 14, 2)->default(0);
            $table->string('bank_name')->nullable();
            $table->string('account_number')->nullable();
            $table->string('ifsc_code', 15)->nullable();
            $table->timestamps();
        });

        // Employee Change Requests (Section 7.3)
        Schema::create('employee_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('requested_by');
            $table->string('field_group'); // personal, statutory, bank, address
            $table->json('current_snapshot');
            $table->json('proposed_values');
            $table->string('status', 20)->default('pending'); // pending, approved, rejected
            $table->string('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_change_requests');
        Schema::dropIfExists('employee_statutory_details');
        
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropColumn(['employee_id', 'role']);
        });

        Schema::dropIfExists('employees');
    }
};
