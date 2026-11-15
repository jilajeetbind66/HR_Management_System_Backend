<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Payroll Runs (Section 7.2 Architecture Rule)
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('period_year');
            $table->unsignedTinyInteger('period_month');
            $table->string('scope_key', 50); // non-null scope: 'company' or 'branch:1'
            $table->string('month_name', 30);
            $table->string('status', 30)->default('review'); // created, calculate_queued, review, approval, locked, paid
            $table->unsignedInteger('total_employees')->default(0);
            $table->decimal('gross_payout_inr', 14, 2)->default(0);
            $table->decimal('total_deductions_inr', 14, 2)->default(0);
            $table->decimal('net_payout_inr', 14, 2)->default(0);
            $table->string('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->date('payout_date')->nullable();
            $table->timestamps();

            // Mandatory Unique constraint from Section 7.2
            $table->unique(['period_year', 'period_month', 'scope_key'], 'payroll_runs_unique_period_scope');
        });

        // Individual Payslips
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            
            // Earnings
            $table->decimal('gross_salary', 14, 2);
            $table->decimal('basic_salary', 14, 2);
            $table->decimal('hra', 14, 2);
            $table->decimal('special_allowance', 14, 2);

            // Deductions
            $table->decimal('pf_employee', 14, 2)->default(0);
            $table->decimal('esi_employee', 14, 2)->default(0);
            $table->decimal('professional_tax', 14, 2)->default(0);
            $table->decimal('tds', 14, 2)->default(0);
            $table->decimal('total_deductions', 14, 2)->default(0);

            // Net
            $table->decimal('net_salary', 14, 2);
            $table->string('payment_status', 20)->default('unpaid'); // unpaid, paid
            $table->timestamps();

            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
        Schema::dropIfExists('payroll_runs');
    }
};
