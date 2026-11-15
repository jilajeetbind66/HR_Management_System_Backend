<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayrollPayslipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
    }

    public function test_run_saves_employee_payslips_and_requires_review_before_publication(): void
    {
        $run = $this->generateRun();
        $payslips = DB::table('payslips')->where('payroll_run_id', $run['id'])->get();

        $this->assertCount(10, $payslips);
        $this->assertSame(
            (float) $payslips->sum('gross_salary'),
            (float) $run['gross_payout_inr']
        );
        $this->getJson("/api/v1/payroll/runs/{$run['id']}/payslips")
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonMissingPath('data.0.bank_name');

        $this->postJson("/api/v1/payroll/runs/{$run['id']}/lock")
            ->assertStatus(409)
            ->assertJsonPath('code', 'PAYSLIP_REVIEW_REQUIRED');

        foreach ($payslips as $payslip) {
            $this->patchJson("/api/v1/payroll/runs/{$run['id']}/payslips/{$payslip->id}/deductions", [
                'professional_tax' => 0,
                'tds' => 0,
            ])->assertOk()->assertJsonPath('data.deductions_confirmed', true);
        }

        $this->postJson("/api/v1/payroll/runs/{$run['id']}/lock", [
            'approved_by' => 'Untrusted Client Name',
        ])->assertOk();

        $this->assertDatabaseHas('payroll_runs', [
            'id' => $run['id'],
            'status' => 'locked',
            'approved_by' => User::where('role', 'super_admin')->value('name'),
        ]);
        $this->assertDatabaseHas('payslips', [
            'payroll_run_id' => $run['id'],
            'deductions_confirmed' => true,
        ]);
        $this->patchJson("/api/v1/payroll/runs/{$run['id']}/payslips/{$payslips->first()->id}/deductions", [
            'professional_tax' => 0,
            'tds' => 0,
        ])->assertStatus(409);
    }

    public function test_employee_and_hr_can_only_read_their_own_finalized_payslips(): void
    {
        $run = $this->generateRun();
        $payslips = DB::table('payslips')->where('payroll_run_id', $run['id'])->get();
        $employee = User::where('role', 'employee')->firstOrFail();

        Sanctum::actingAs($employee);
        $this->getJson('/api/v1/payroll/payslips/mine')->assertOk()->assertJsonCount(0, 'data');

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        foreach ($payslips as $payslip) {
            $this->patchJson("/api/v1/payroll/runs/{$run['id']}/payslips/{$payslip->id}/deductions", [
                'professional_tax' => 0,
                'tds' => 0,
            ])->assertOk();
        }
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/lock")->assertOk();

        Sanctum::actingAs($employee);
        $this->getJson('/api/v1/payroll/payslips/mine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_code', DB::table('employees')->where('id', $employee->employee_id)->value('employee_code'))
            ->assertJsonMissingPath('data.0.account_number');

        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        Sanctum::actingAs($hrAdmin);
        $this->getJson('/api/v1/payroll/payslips/mine')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.employee_code', DB::table('employees')->where('id', $hrAdmin->employee_id)->value('employee_code'));
        $this->getJson('/api/v1/payroll/runs')->assertForbidden();
    }

    public function test_payroll_rejects_invalid_salary_data_and_unlinked_self_service_accounts(): void
    {
        $employeeId = DB::table('employees')->value('id');
        DB::table('employee_statutory_details')
            ->where('employee_id', $employeeId)
            ->update(['gross_monthly' => 0]);

        $this->postJson('/api/v1/payroll/runs', [
            'period_year' => 2027,
            'period_month' => 1,
            'scope_key' => 'company',
        ])->assertStatus(422)->assertJsonPath('code', 'PAYROLL_SALARY_DATA_INVALID');

        $this->assertDatabaseMissing('payroll_runs', [
            'period_year' => 2027,
            'period_month' => 1,
            'scope_key' => 'company',
        ]);

        $unlinkedUser = User::factory()->create([
            'name' => 'Unlinked Employee',
            'email' => 'unlinked.employee@example.com',
            'role' => 'employee',
        ]);
        Sanctum::actingAs($unlinkedUser);
        $this->getJson('/api/v1/payroll/payslips/mine')->assertForbidden();
    }

    public function test_only_payroll_admins_can_review_employee_payslips(): void
    {
        $run = $this->generateRun();
        $payslipId = DB::table('payslips')->where('payroll_run_id', $run['id'])->value('id');

        Sanctum::actingAs(User::where('role', 'auditor')->firstOrFail());
        $this->getJson("/api/v1/payroll/runs/{$run['id']}/payslips")->assertForbidden();
        $this->patchJson("/api/v1/payroll/runs/{$run['id']}/payslips/{$payslipId}/deductions", [
            'professional_tax' => 0,
            'tds' => 0,
        ])->assertForbidden();
    }

    public function test_branch_scoped_run_only_includes_employees_from_that_branch(): void
    {
        $branchId = (int) DB::table('employees')->value('branch_id');
        $expectedCount = DB::table('employees')
            ->where('branch_id', $branchId)
            ->whereIn('status', ['active', 'on_notice'])
            ->count();

        $run = $this->postJson('/api/v1/payroll/runs', [
            'period_year' => 2027,
            'period_month' => 2,
            'scope_key' => "branch:{$branchId}",
        ])->assertCreated()->json('data');

        $this->assertSame($expectedCount, (int) $run['total_employees']);
        $this->assertSame(
            0,
            DB::table('payslips')
                ->join('employees', 'employees.id', '=', 'payslips.employee_id')
                ->where('payslips.payroll_run_id', $run['id'])
                ->where('employees.branch_id', '!=', $branchId)
                ->count()
        );
    }

    private function generateRun(): array
    {
        return $this->postJson('/api/v1/payroll/runs', [
            'period_year' => 2027,
            'period_month' => 1,
            'scope_key' => 'company',
            'month_name' => 'January 2027',
        ])->assertCreated()->json('data');
    }
}
