<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;
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

        $this->assertSame('draft', $run['status']);
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/verify")
            ->assertStatus(409)
            ->assertJsonPath('code', 'PAYSLIP_REVIEW_REQUIRED');

        foreach ($payslips as $payslip) {
            $this->patchJson("/api/v1/payroll/runs/{$run['id']}/payslips/{$payslip->id}/deductions", [
                'professional_tax' => 0,
                'tds' => 0,
                'tds_override_reason' => 'No verified tax table is configured in this isolated test.',
            ])->assertOk()->assertJsonPath('data.deductions_confirmed', true);
        }

        $this->postJson("/api/v1/payroll/runs/{$run['id']}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'verified');
        $this->patchJson("/api/v1/payroll/runs/{$run['id']}/payslips/{$payslips->first()->id}/deductions", [
            'professional_tax' => 0,
            'tds' => 0,
        ])->assertStatus(409);

        $this->postJson("/api/v1/payroll/runs/{$run['id']}/publish")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

        $this->assertDatabaseHas('payroll_runs', [
            'id' => $run['id'],
            'status' => 'published',
            'approved_by' => User::where('role', 'super_admin')->value('name'),
        ]);
        $this->assertDatabaseHas('payslips', [
            'payroll_run_id' => $run['id'],
            'deductions_confirmed' => true,
        ]);
        foreach (['PAYROLL_RUN_CREATED', 'PAYROLL_VERIFIED', 'PAYROLL_PUBLISHED'] as $action) {
            $this->assertDatabaseHas('audit_logs', [
                'action' => $action,
                'module' => 'Payroll',
            ]);
        }
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
                'tds_override_reason' => 'No verified tax table is configured in this isolated test.',
            ])->assertOk();
        }
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/verify")->assertOk();

        Sanctum::actingAs($employee);
        $this->getJson('/api/v1/payroll/payslips/mine')->assertOk()->assertJsonCount(0, 'data');
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->postJson("/api/v1/payroll/runs/{$run['id']}/lock")
            ->assertOk()
            ->assertJsonPath('data.status', 'published');

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
        ])->assertStatus(422)
            ->assertJsonPath('code', 'PAYROLL_SALARY_DATA_INVALID')
            ->assertJsonPath('invalid_employees.0.employee_id', $employeeId)
            ->assertJsonPath('invalid_employees.0.employee_code', DB::table('employees')->where('id', $employeeId)->value('employee_code'))
            ->assertJsonPath('invalid_employees.0.issues', ['gross_monthly', 'basic_exceeds_gross']);

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

    public function test_demo_employees_are_excluded_from_regular_payroll_and_have_an_explicit_test_scope(): void
    {
        $demoEmployeeId = (int) DB::table('employees')
            ->where('status', 'active')
            ->orderBy('id')
            ->value('id');
        DB::table('employees')->where('id', $demoEmployeeId)->update(['is_demo' => true]);

        $companyRun = $this->postJson('/api/v1/payroll/runs', [
            'period_year' => 2027,
            'period_month' => 3,
            'scope_key' => 'company',
        ])->assertCreated()->json('data');
        $this->assertDatabaseMissing('payslips', [
            'payroll_run_id' => $companyRun['id'],
            'employee_id' => $demoEmployeeId,
        ]);
        $this->assertSame(
            DB::table('employees')->where('status', 'active')->where('is_demo', false)->count(),
            (int) $companyRun['total_employees']
        );

        $demoRun = $this->postJson('/api/v1/payroll/runs', [
            'period_year' => 2027,
            'period_month' => 3,
            'scope_key' => 'demo',
        ])->assertCreated()->json('data');
        $this->assertSame('demo', $demoRun['scope_key']);
        $this->assertSame(1, (int) $demoRun['total_employees']);
        $this->assertDatabaseHas('payslips', [
            'payroll_run_id' => $demoRun['id'],
            'employee_id' => $demoEmployeeId,
        ]);
    }

    public function test_approved_unpaid_leave_is_deducted_using_the_payroll_month_calendar_days(): void
    {
        Carbon::setTestNow('2026-11-17 12:00:00');
        try {
            $employeeId = (int) DB::table('employees')->value('id');
            $leaveDate = Carbon::create(2026, 11, 18);
            while ($leaveDate->isWeekend()) {
                $leaveDate->addDay();
            }

            DB::table('holidays')->whereDate('date', $leaveDate->toDateString())->delete();
            DB::table('leave_requests')->where('employee_id', $employeeId)
                ->whereBetween('from_date', ['2026-11-01', '2026-11-30'])
                ->delete();
            DB::table('employee_statutory_details')->where('employee_id', $employeeId)->update([
                'gross_monthly' => 30000,
                'basic_monthly' => 15000,
            ]);
            DB::table('leave_requests')->insert([
                'employee_id' => $employeeId,
                'leave_type' => 'Leave Without Pay (LOP)',
                'from_date' => $leaveDate->toDateString(),
                'to_date' => $leaveDate->toDateString(),
                'days' => 0.5,
                'reason' => 'Approved unpaid leave for payroll.',
                'status' => 'approved',
                'approval_stage' => 'Approved',
                'is_half_day' => true,
                'half_day_period' => 'first_half',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $run = $this->postJson('/api/v1/payroll/runs', [
                'period_year' => 2026,
                'period_month' => 11,
                'scope_key' => 'company',
            ])->assertCreated()->json('data');
            $payslip = DB::table('payslips')
                ->where('payroll_run_id', $run['id'])
                ->where('employee_id', $employeeId)
                ->first();

            $this->assertSame(0.5, (float) $payslip->lop_days);
            $this->assertSame(500.0, (float) $payslip->lop_deduction);
            $this->assertSame(
                round((float) $payslip->pf_employee + (float) $payslip->esi_employee + 500, 2),
                (float) $payslip->total_deductions
            );

            $reviewedPayslip = $this->patchJson("/api/v1/payroll/runs/{$run['id']}/payslips/{$payslip->id}/deductions", [
                'professional_tax' => 0,
                'tds' => 0,
                'tds_override_reason' => 'No verified tax table is configured in this isolated test.',
            ])->assertOk()->json('data');
            $this->assertSame(0.5, (float) $reviewedPayslip['lop_days']);
            $this->assertSame(500.0, (float) $reviewedPayslip['lop_deduction']);
            $this->assertSame((float) $payslip->total_deductions, (float) $reviewedPayslip['total_deductions']);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_explicit_attendance_lop_is_added_once_and_combined_with_approved_lop_leave(): void
    {
        Carbon::setTestNow('2026-11-17 12:00:00');
        try {
            $employeeId = (int) DB::table('employees')->value('id');
            DB::table('holidays')->whereIn('date', ['2026-11-18', '2026-11-19', '2026-11-20'])->delete();
            DB::table('attendance_records')
                ->where('employee_id', $employeeId)
                ->whereIn('date', ['2026-11-18', '2026-11-19', '2026-11-20'])
                ->delete();
            DB::table('leave_requests')
                ->where('employee_id', $employeeId)
                ->whereBetween('from_date', ['2026-11-01', '2026-11-30'])
                ->delete();
            DB::table('attendance_records')->insert([
                [
                    'employee_id' => $employeeId,
                    'date' => '2026-11-18',
                    'status' => 'Absent',
                    'work_hours' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'employee_id' => $employeeId,
                    'date' => '2026-11-19',
                    'status' => 'Half Day',
                    'work_hours' => 3,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'employee_id' => $employeeId,
                    'date' => '2026-11-20',
                    'status' => 'Absent',
                    'work_hours' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            ]);
            DB::table('leave_requests')->insert([
                'employee_id' => $employeeId,
                'leave_type' => 'Leave Without Pay (LOP)',
                'from_date' => '2026-11-20',
                'to_date' => '2026-11-20',
                'days' => 1,
                'reason' => 'Approved unpaid leave overlapping an absent attendance day.',
                'status' => 'approved',
                'approval_stage' => 'Approved',
                'is_half_day' => false,
                'counted_dates' => json_encode(['2026-11-20']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $run = $this->postJson('/api/v1/payroll/runs', [
                'period_year' => 2026,
                'period_month' => 11,
                'scope_key' => 'company',
            ])->assertCreated()->json('data');
            $payslip = DB::table('payslips')
                ->where('payroll_run_id', $run['id'])
                ->where('employee_id', $employeeId)
                ->first();

            $this->assertSame(2.5, (float) $payslip->lop_days);
        } finally {
            Carbon::setTestNow();
        }
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
