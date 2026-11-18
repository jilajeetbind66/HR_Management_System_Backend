<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_statutory_configs', function (Blueprint $table) {
            $table->id();
            $table->date('effective_from')->unique();
            $table->decimal('pf_employee_rate', 6, 3);
            $table->decimal('pf_employer_rate', 6, 3);
            $table->decimal('eps_rate', 6, 3);
            $table->decimal('pf_wage_ceiling', 12, 2);
            $table->boolean('apply_pf_wage_ceiling')->default(true);
            $table->decimal('esi_employee_rate', 6, 3);
            $table->decimal('esi_employer_rate', 6, 3);
            $table->decimal('esi_gross_limit', 12, 2);
            $table->timestamps();
        });

        Schema::create('payroll_pt_rule_sets', function (Blueprint $table) {
            $table->id();
            $table->string('state', 100);
            $table->boolean('is_applicable');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->unique(['state', 'effective_from']);
        });

        Schema::create('payroll_pt_slabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_set_id')->constrained('payroll_pt_rule_sets')->cascadeOnDelete();
            $table->decimal('salary_from', 12, 2);
            $table->decimal('salary_to', 12, 2)->nullable();
            $table->decimal('monthly_tax', 12, 2);
            $table->timestamps();
            $table->unique(['rule_set_id', 'salary_from']);
        });

        Schema::create('payroll_tax_rule_sets', function (Blueprint $table) {
            $table->id();
            $table->string('tax_regime', 10);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestamps();
            $table->unique(['tax_regime', 'effective_from']);
        });

        Schema::create('payroll_tax_slabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_set_id')->constrained('payroll_tax_rule_sets')->cascadeOnDelete();
            $table->decimal('income_from', 14, 2);
            $table->decimal('income_to', 14, 2)->nullable();
            $table->decimal('tax_rate', 6, 3);
            $table->timestamps();
            $table->unique(['rule_set_id', 'income_from']);
        });

        Schema::create('employee_tax_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('financial_year', 9);
            $table->string('tax_regime', 10);
            $table->decimal('other_annual_income', 14, 2)->default(0);
            $table->decimal('previous_employer_income', 14, 2)->default(0);
            $table->decimal('previous_employer_tds', 14, 2)->default(0);
            $table->json('annual_deductions');
            $table->string('reviewed_by');
            $table->timestamp('reviewed_at');
            $table->timestamps();
            $table->unique(['employee_id', 'financial_year']);
        });

        DB::table('payroll_statutory_configs')->insert([
            'effective_from' => '2026-04-01',
            'pf_employee_rate' => 12,
            'pf_employer_rate' => 12,
            'eps_rate' => 8.33,
            'pf_wage_ceiling' => 15000,
            'apply_pf_wage_ceiling' => true,
            'esi_employee_rate' => 0.75,
            'esi_employer_rate' => 3.25,
            'esi_gross_limit' => 21000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('professional_tax_computed', 14, 2)->nullable();
            $table->decimal('tds_computed', 14, 2)->nullable();
            $table->decimal('tds_taxable_annual_income', 14, 2)->nullable();
            $table->text('professional_tax_override_reason')->nullable();
            $table->text('tds_override_reason')->nullable();
            $table->string('tax_financial_year', 9)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn([
                'professional_tax_computed',
                'tds_computed',
                'tds_taxable_annual_income',
                'professional_tax_override_reason',
                'tds_override_reason',
                'tax_financial_year',
            ]);
        });

        Schema::dropIfExists('employee_tax_declarations');
        Schema::dropIfExists('payroll_tax_slabs');
        Schema::dropIfExists('payroll_tax_rule_sets');
        Schema::dropIfExists('payroll_pt_slabs');
        Schema::dropIfExists('payroll_pt_rule_sets');
        Schema::dropIfExists('payroll_statutory_configs');
    }
};
