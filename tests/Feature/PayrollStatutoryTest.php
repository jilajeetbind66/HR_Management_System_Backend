<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayrollStatutoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
    }

    public function test_statutory_configuration_is_effective_dated_and_role_restricted(): void
    {
        $this->getJson('/api/v1/payroll/statutory-config')
            ->assertOk()
            ->assertJsonPath('data.contribution_configs.0.pf_wage_ceiling', 15000)
            ->assertJsonCount(0, 'data.professional_tax_rule_sets')
            ->assertJsonCount(0, 'data.income_tax_rule_sets');

        Sanctum::actingAs(User::where('role', 'hr_admin')->firstOrFail());
        $this->postJson('/api/v1/payroll/statutory-config/contributions', [
            'effective_from' => '2027-01-01',
            'pf_employee_rate' => 12,
            'pf_employer_rate' => 12,
            'eps_rate' => 8.33,
            'pf_wage_ceiling' => 15000,
            'apply_pf_wage_ceiling' => true,
            'esi_employee_rate' => 0.75,
            'esi_employer_rate' => 3.25,
            'esi_gross_limit' => 21000,
        ])->assertCreated();
        $branchId = (int) DB::table('branches')->value('id');
        $this->patchJson("/api/v1/payroll/statutory-config/branches/{$branchId}/professional-tax", [
            'pt_applicable' => true,
        ])->assertOk();
        $this->assertDatabaseHas('branches', ['id' => $branchId, 'pt_applicable' => true]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'BRANCH_PT_APPLICABILITY_UPDATED']);

        Sanctum::actingAs(User::where('role', 'payroll_admin')->firstOrFail());
        $this->getJson('/api/v1/payroll/statutory-config')->assertOk();
        $this->postJson('/api/v1/payroll/statutory-config/contributions', [
            'effective_from' => '2028-01-01',
            'pf_employee_rate' => 12,
            'pf_employer_rate' => 12,
            'eps_rate' => 8.33,
            'pf_wage_ceiling' => 15000,
            'apply_pf_wage_ceiling' => true,
            'esi_employee_rate' => 0.75,
            'esi_employer_rate' => 3.25,
            'esi_gross_limit' => 21000,
        ])->assertForbidden();
        $this->patchJson("/api/v1/payroll/statutory-config/branches/{$branchId}/professional-tax", [
            'pt_applicable' => false,
        ])->assertForbidden();
    }

    public function test_professional_tax_and_capped_employer_eps_epf_are_applied_from_config(): void
    {
        $employee = DB::table('employees')->orderBy('id')->first();
        DB::table('branches')->where('id', $employee->branch_id)->update(['pt_applicable' => true]);

        $this->postJson('/api/v1/payroll/statutory-config/professional-tax', [
            'state' => 'Uttar Pradesh',
            'is_applicable' => true,
            'effective_from' => '2026-04-01',
            'slabs' => [
                ['salary_from' => 0, 'salary_to' => null, 'monthly_tax' => 150],
            ],
        ])->assertCreated();

        $run = $this->postJson('/api/v1/payroll/runs', [
            'period_year' => 2027,
            'period_month' => 1,
            'scope_key' => 'company',
        ])->assertCreated()->json('data');
        $payslip = DB::table('payslips')->where([
            'payroll_run_id' => $run['id'],
            'employee_id' => $employee->id,
        ])->first();
        $breakdown = json_decode($payslip->component_breakdown, true);

        $this->assertSame(150.0, (float) $payslip->professional_tax_computed);
        $this->assertSame(1800.0, (float) $payslip->pf_employee);
        $this->assertSame(1249.5, (float) collect($breakdown['employer_costs'])->firstWhere('component_key', 'employer_eps')['amount']);
        $this->assertSame(550.5, (float) collect($breakdown['employer_costs'])->firstWhere('component_key', 'employer_pf')['amount']);

        $disabledBranchEmployee = DB::table('employees')
            ->where('branch_id', '!=', $employee->branch_id)
            ->first();
        $disabledBranchPayslip = DB::table('payslips')->where([
            'payroll_run_id' => $run['id'],
            'employee_id' => $disabledBranchEmployee->id,
        ])->first();
        $this->assertSame(0.0, (float) $disabledBranchPayslip->professional_tax_computed);

        $this->postJson('/api/v1/payroll/statutory-config/contributions', [
            'effective_from' => '2027-02-01',
            'pf_employee_rate' => 12,
            'pf_employer_rate' => 12,
            'eps_rate' => 8.33,
            'pf_wage_ceiling' => 15000,
            'apply_pf_wage_ceiling' => false,
            'esi_employee_rate' => 0.75,
            'esi_employer_rate' => 3.25,
            'esi_gross_limit' => 21000,
        ])->assertCreated();
        $februaryRun = $this->postJson('/api/v1/payroll/runs', [
            'period_year' => 2027,
            'period_month' => 2,
            'scope_key' => 'company',
        ])->assertCreated()->json('data');
        $februaryPayslip = DB::table('payslips')->where([
            'payroll_run_id' => $februaryRun['id'],
            'employee_id' => $employee->id,
        ])->first();
        $this->assertSame(18000.0, (float) $februaryPayslip->pf_employee);
    }

    public function test_tds_uses_reviewed_regime_declarations_and_progressive_effective_slabs(): void
    {
        $employee = DB::table('employees')->orderBy('id')->first();
        $this->postJson('/api/v1/payroll/statutory-config/income-tax', [
            'tax_regime' => 'New',
            'effective_from' => '2026-04-01',
            'slabs' => [
                ['income_from' => 0, 'income_to' => 200000, 'tax_rate' => 0],
                ['income_from' => 200000, 'income_to' => null, 'tax_rate' => 10],
            ],
        ])->assertCreated();
        $this->putJson("/api/v1/payroll/tax-declarations/{$employee->id}", [
            'financial_year' => '2026-2027',
            'tax_regime' => 'New',
            'other_annual_income' => 0,
            'previous_employer_income' => 100000,
            'previous_employer_tds' => 5000,
            'annual_deductions' => [
                'section_80c' => 10000,
                'section_80d' => 0,
                'section_80ccd_1b' => 0,
                'hra_exemption' => 0,
                'home_loan_interest' => 0,
                'other_deductions' => 0,
            ],
        ])->assertOk();

        $run = $this->postJson('/api/v1/payroll/runs', [
            'period_year' => 2027,
            'period_month' => 1,
            'scope_key' => 'company',
        ])->assertCreated()->json('data');
        $payslip = DB::table('payslips')->where([
            'payroll_run_id' => $run['id'],
            'employee_id' => $employee->id,
        ])->first();

        $this->assertSame(990000.0, (float) $payslip->tds_taxable_annual_income);
        $this->assertSame(24666.67, (float) $payslip->tds_computed);
        $this->assertSame('2026-2027', $payslip->tax_financial_year);

        $this->patchJson("/api/v1/payroll/runs/{$run['id']}/payslips/{$payslip->id}/deductions", [
            'professional_tax' => $payslip->professional_tax,
            'tds' => 10000,
        ])->assertStatus(422)->assertJsonValidationErrors('tds_override_reason');
        $this->patchJson("/api/v1/payroll/runs/{$run['id']}/payslips/{$payslip->id}/deductions", [
            'professional_tax' => $payslip->professional_tax,
            'tds' => 10000,
            'tds_override_reason' => 'Verified employee declaration requires lower monthly withholding.',
        ])->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'PAYSLIP_DEDUCTIONS_OVERRIDDEN',
            'module' => 'Payroll',
        ]);
        $this->assertStringContainsString(
            'Verified employee declaration',
            DB::table('audit_logs')->where('action', 'PAYSLIP_DEDUCTIONS_OVERRIDDEN')->value('description')
        );
    }

    public function test_missing_pt_or_tds_configuration_requires_manual_override_reason(): void
    {
        $employee = DB::table('employees')->orderBy('id')->first();
        DB::table('branches')->where('id', $employee->branch_id)->update(['pt_applicable' => true]);
        $run = $this->postJson('/api/v1/payroll/runs', [
            'period_year' => 2027,
            'period_month' => 1,
            'scope_key' => 'company',
        ])->assertCreated()->json('data');
        $payslip = DB::table('payslips')->where([
            'payroll_run_id' => $run['id'],
            'employee_id' => $employee->id,
        ])->first();

        $this->assertNull($payslip->professional_tax_computed);
        $this->assertNull($payslip->tds_computed);
        $this->patchJson("/api/v1/payroll/runs/{$run['id']}/payslips/{$payslip->id}/deductions", [
            'professional_tax' => 0,
            'tds' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors([
            'professional_tax_override_reason',
            'tds_override_reason',
        ]);
    }

    public function test_esi_employee_and_employer_rates_use_the_effective_contribution_config(): void
    {
        $employee = DB::table('employees')->orderBy('id')->first();
        DB::table('employee_statutory_details')->where('employee_id', $employee->id)->update([
            'gross_monthly' => 20000,
            'basic_monthly' => 10000,
            'esi_applicable' => true,
        ]);
        $this->postJson('/api/v1/payroll/statutory-config/contributions', [
            'effective_from' => '2027-01-01',
            'pf_employee_rate' => 12,
            'pf_employer_rate' => 12,
            'eps_rate' => 8.33,
            'pf_wage_ceiling' => 15000,
            'apply_pf_wage_ceiling' => true,
            'esi_employee_rate' => 0.5,
            'esi_employer_rate' => 3,
            'esi_gross_limit' => 21000,
        ])->assertCreated();

        $run = $this->postJson('/api/v1/payroll/runs', [
            'period_year' => 2027,
            'period_month' => 1,
            'scope_key' => 'company',
        ])->assertCreated()->json('data');
        $payslip = DB::table('payslips')->where([
            'payroll_run_id' => $run['id'],
            'employee_id' => $employee->id,
        ])->first();
        $breakdown = json_decode($payslip->component_breakdown, true);

        $this->assertSame(100.0, (float) $payslip->esi_employee);
        $this->assertSame(600.0, (float) collect($breakdown['employer_costs'])->firstWhere('component_key', 'employer_esi')['amount']);
    }
}
