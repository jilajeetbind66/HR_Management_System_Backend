<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayrollComponentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
    }

    public function test_default_hra_and_component_breakdown_are_snapshotted_per_payroll_run(): void
    {
        $employees = DB::table('employees')->orderBy('id')->limit(2)->get();
        $branchIds = DB::table('branches')->orderBy('id')->limit(2)->pluck('id');
        DB::table('employees')->where('id', $employees[0]->id)->update(['branch_id' => $branchIds[0]]);
        DB::table('employees')->where('id', $employees[1]->id)->update(['branch_id' => $branchIds[1]]);
        DB::table('branches')->where('id', $branchIds[0])->update(['city' => 'Delhi']);
        DB::table('branches')->where('id', $branchIds[1])->update(['city' => 'Pune']);

        $run = $this->postJson('/api/v1/payroll/runs', [
            'period_year' => 2027,
            'period_month' => 1,
            'scope_key' => 'company',
        ])->assertCreated()->json('data');

        foreach ([[$employees[0], 50], [$employees[1], 40]] as [$employee, $rate]) {
            $payslip = DB::table('payslips')->where([
                'payroll_run_id' => $run['id'],
                'employee_id' => $employee->id,
            ])->first();
            $breakdown = json_decode($payslip->component_breakdown, true);
            $basic = (float) $payslip->basic_salary;
            $gross = (float) $payslip->gross_salary;

            $this->assertSame(round($basic * $rate / 100, 2), (float) $payslip->hra);
            $this->assertSame($gross, (float) array_sum(array_column($breakdown['earnings'], 'amount')));
            $this->assertSame((float) $payslip->employer_cost_total, (float) array_sum(array_column($breakdown['employer_costs'], 'amount')));
            $this->assertSame($gross + (float) $payslip->employer_cost_total, (float) $payslip->total_ctc_monthly);
            $this->assertSame((float) DB::table('employee_statutory_details')->where('employee_id', $employee->id)->value('ctc_annual'), (float) $payslip->ctc_annual);
        }

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->getJson("/api/v1/payroll/runs/{$run['id']}/payslips")
            ->assertOk()
            ->assertJsonPath('data.0.component_breakdown.earnings.0.component_key', 'basic');
    }

    public function test_optional_component_formulas_and_required_components_are_validated(): void
    {
        $conveyanceId = DB::table('payroll_components')->where('component_key', 'conveyance')->value('id');
        $gratuityId = DB::table('payroll_components')->where('component_key', 'gratuity')->value('id');

        $this->patchJson("/api/v1/payroll/components/{$conveyanceId}", [
            'value' => 1000,
            'is_enabled' => true,
        ])->assertOk();
        $this->patchJson("/api/v1/payroll/components/{$gratuityId}", [
            'value' => 4.81,
            'is_enabled' => true,
        ])->assertOk();

        $employee = DB::table('employees')->first();
        DB::table('employee_statutory_details')->where('employee_id', $employee->id)->update([
            'pf_applicable' => true,
            'esi_applicable' => true,
        ]);
        $run = $this->postJson('/api/v1/payroll/runs', [
            'period_year' => 2027,
            'period_month' => 2,
            'scope_key' => 'company',
        ])->assertCreated()->json('data');
        $payslip = DB::table('payslips')->where([
            'payroll_run_id' => $run['id'],
            'employee_id' => $employee->id,
        ])->first();
        $breakdown = json_decode($payslip->component_breakdown, true);

        $this->assertSame(1000.0, (float) collect($breakdown['earnings'])->firstWhere('component_key', 'conveyance')['amount']);
        $this->assertSame(
            round((float) $payslip->basic_salary * 0.0481, 2),
            (float) collect($breakdown['employer_costs'])->firstWhere('component_key', 'gratuity')['amount']
        );
        $this->assertGreaterThan(0, (float) $payslip->employer_cost_total);

        $specialAllowanceId = DB::table('payroll_components')->where('component_key', 'special_allowance')->value('id');
        $this->patchJson("/api/v1/payroll/components/{$specialAllowanceId}", [
            'is_enabled' => false,
        ])->assertStatus(422);

        $this->patchJson("/api/v1/payroll/components/{$conveyanceId}", [
            'value' => 99999999,
        ])->assertOk();
        $this->postJson('/api/v1/payroll/runs', [
            'period_year' => 2027,
            'period_month' => 3,
            'scope_key' => 'company',
        ])->assertStatus(422)->assertJsonValidationErrors('components');
        $this->assertDatabaseMissing('payroll_runs', [
            'period_year' => 2027,
            'period_month' => 3,
            'scope_key' => 'company',
        ]);
    }

    public function test_hr_admin_can_manage_components_without_payroll_run_permissions_and_audits_changes(): void
    {
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        Sanctum::actingAs($hrAdmin);

        $this->getJson('/api/v1/payroll/components')->assertOk();
        $conveyanceId = DB::table('payroll_components')->where('component_key', 'conveyance')->value('id');
        $this->patchJson("/api/v1/payroll/components/{$conveyanceId}", [
            'value' => 1250,
        ])->assertOk();
        $this->postJson('/api/v1/payroll/components', [
            'name' => 'Wellness Allowance',
            'component_group' => 'earning',
            'calculation_type' => 'fixed',
            'value' => 500,
            'is_enabled' => false,
        ])->assertCreated();
        $this->postJson('/api/v1/payroll/runs', [
            'period_year' => 2027,
            'period_month' => 3,
            'scope_key' => 'company',
        ])->assertForbidden();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'PAYROLL_COMPONENT_UPDATED',
            'actor' => $hrAdmin->name,
            'module' => 'Payroll',
        ]);
        $updateDescription = DB::table('audit_logs')
            ->where('action', 'PAYROLL_COMPONENT_UPDATED')
            ->value('description');
        $this->assertStringContainsString('value', $updateDescription);
        $this->assertStringNotContainsString('1250', $updateDescription);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'PAYROLL_COMPONENT_CREATED',
            'actor' => $hrAdmin->name,
            'module' => 'Payroll',
        ]);

        Sanctum::actingAs(User::where('role', 'payroll_admin')->firstOrFail());
        $this->getJson('/api/v1/payroll/components')->assertOk();
        $this->patchJson("/api/v1/payroll/components/{$conveyanceId}", [
            'value' => 1500,
        ])->assertForbidden();
    }

    public function test_salary_configuration_summary_excludes_demo_and_incomplete_employees(): void
    {
        $employees = DB::table('employees')
            ->whereIn('status', ['active', 'on_notice'])
            ->where('is_demo', false)
            ->orderBy('id')
            ->limit(2)
            ->get();
        $this->assertCount(2, $employees);

        DB::table('employee_statutory_details')
            ->where('employee_id', $employees[0]->id)
            ->update(['gross_monthly' => 0]);
        DB::table('employees')->where('id', $employees[1]->id)->update(['is_demo' => true]);

        $response = $this->getJson('/api/v1/payroll/components')->assertOk();
        $this->assertSame(
            DB::table('employees')->whereIn('status', ['active', 'on_notice'])->where('is_demo', false)->count(),
            $response->json('meta.salary_configuration.total_count')
        );
        $this->assertSame(
            DB::table('employees')
                ->join('employee_statutory_details', 'employees.id', '=', 'employee_statutory_details.employee_id')
                ->whereIn('employees.status', ['active', 'on_notice'])
                ->where('employees.is_demo', false)
                ->where('employee_statutory_details.gross_monthly', '>', 0)
                ->where('employee_statutory_details.basic_monthly', '>', 0)
                ->whereColumn('employee_statutory_details.basic_monthly', '<=', 'employee_statutory_details.gross_monthly')
                ->count(),
            $response->json('meta.salary_configuration.configured_count')
        );
        $this->assertLessThan(
            $response->json('meta.salary_configuration.total_count'),
            $response->json('meta.salary_configuration.configured_count')
        );
    }
}
