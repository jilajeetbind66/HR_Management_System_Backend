<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportsAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_manager_reports_and_employee_export_are_limited_to_direct_reports(): void
    {
        $manager = User::where('role', 'manager')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        DB::table('employees')
            ->where('id', $employee->employee_id)
            ->update(['reporting_manager_id' => $manager->employee_id]);
        Sanctum::actingAs($manager);

        $summary = $this->getJson('/api/v1/reports/summary')->assertOk();
        $summary
            ->assertJsonPath('data.workforce.active_employee_count', 1)
            ->assertJsonPath('data.payroll', null)
            ->assertJsonPath('data.exports.pf_source_register', false);

        $export = $this->getJson('/api/v1/reports/exports/employee_master')
            ->assertOk()
            ->json('data.rows');
        $this->assertSame(
            [DB::table('employees')->where('id', $employee->employee_id)->value('employee_code')],
            array_column($export, 'employee_code')
        );
        $this->assertArrayNotHasKey('uan', $export[0]);
        $this->assertArrayNotHasKey('gross_monthly', $export[0]);
        $this->getJson('/api/v1/reports/exports/pf_source_register?run_id=1')
            ->assertForbidden();
    }

    public function test_payroll_admin_can_export_only_locked_payroll_source_data(): void
    {
        $payrollAdmin = User::factory()->create([
            'name' => 'Payroll Admin',
            'email' => 'reports.payroll@example.com',
        ]);
        $payrollAdmin->role = 'payroll_admin';
        $payrollAdmin->save();

        $employee = User::where('role', 'employee')->firstOrFail();
        DB::table('employee_statutory_details')
            ->where('employee_id', $employee->employee_id)
            ->update([
                'uan' => '123456789012',
                'pan' => 'ABCDE1234F',
                'pt_state' => 'Uttar Pradesh',
            ]);
        $runId = DB::table('payroll_runs')->insertGetId([
            'period_year' => 2026,
            'period_month' => 11,
            'scope_key' => 'company',
            'month_name' => 'November 2026',
            'status' => 'locked',
            'total_employees' => 1,
            'gross_payout_inr' => 50000,
            'total_deductions_inr' => 3700,
            'net_payout_inr' => 46300,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payslips')->insert([
            'payroll_run_id' => $runId,
            'employee_id' => $employee->employee_id,
            'gross_salary' => 50000,
            'basic_salary' => 25000,
            'hra' => 10000,
            'special_allowance' => 15000,
            'pf_employee' => 3000,
            'esi_employee' => 0,
            'professional_tax' => 200,
            'tds' => 500,
            'total_deductions' => 3700,
            'net_salary' => 46300,
            'payment_status' => 'paid',
            'deductions_confirmed' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($payrollAdmin);

        $summary = $this->getJson('/api/v1/reports/summary')->assertOk();
        $summary
            ->assertJsonPath('data.workforce', null)
            ->assertJsonPath('data.payroll.latest_locked_run.id', $runId);
        $summary
            ->assertJsonPath('data.exports.employee_master', false)
            ->assertJsonPath('data.exports.pf_source_register', true);

        $response = $this->getJson("/api/v1/reports/exports/pf_source_register?run_id={$runId}")
            ->assertOk()
            ->assertJsonPath('data.missing_identifier_count', 0)
            ->assertJsonPath('data.rows.0.uan', '123456789012')
            ->assertJsonPath('data.rows.0.basic_salary', 25000);
        $this->assertStringContainsString('not an EPFO ECR', $response->json('data.disclaimer'));
        $this->getJson("/api/v1/reports/exports/pt_source_register?run_id={$runId}")
            ->assertOk()
            ->assertJsonPath('data.rows.0.pt_state', 'Uttar Pradesh')
            ->assertJsonPath('data.rows.0.professional_tax_recorded', 200);
        $this->getJson("/api/v1/reports/exports/tds_source_register?run_id={$runId}")
            ->assertOk()
            ->assertJsonPath('data.rows.0.pan', 'ABCDE1234F')
            ->assertJsonPath('data.rows.0.tds_recorded', 500);
        $this->getJson('/api/v1/reports/exports/employee_master')->assertForbidden();

        DB::table('payroll_runs')->where('id', $runId)->update(['status' => 'review']);
        $this->getJson("/api/v1/reports/exports/pf_source_register?run_id={$runId}")
            ->assertNotFound();
    }

    public function test_auditor_gets_aggregate_reports_but_not_employee_level_payroll_exports(): void
    {
        $auditor = User::factory()->create([
            'name' => 'Report Auditor',
            'email' => 'reports.auditor@example.com',
        ]);
        $auditor->role = 'auditor';
        $auditor->save();
        Sanctum::actingAs($auditor);

        $summary = $this->getJson('/api/v1/reports/summary')->assertOk();
        $this->assertIsArray($summary->json('data.workforce'));
        $this->assertFalse($summary->json('data.exports.pf_source_register'));
        $this->getJson('/api/v1/reports/exports/tds_source_register?run_id=1')
            ->assertForbidden();
    }

    public function test_project_delivery_rate_uses_completed_assignment_dates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-14 12:00:00'));
        $admin = User::where('role', 'super_admin')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        Sanctum::actingAs($admin);
        $baseline = $this->getJson('/api/v1/reports/summary')
            ->assertOk()
            ->json('data.projects');
        $projectId = DB::table('projects')->insertGetId([
            'project_code' => 'REPORT-001',
            'name' => 'Report delivery test',
            'description' => 'Test assignment metrics',
            'deliverable' => 'Report',
            'priority' => 'Medium',
            'status' => 'completed',
            'created_by' => 'Test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('project_assignments')->insert([
            'project_id' => $projectId,
            'employee_id' => $employee->employee_id,
            'assigned_by' => 'Test',
            'start_date' => '2026-11-01',
            'due_date' => '2026-11-10',
            'active_due_date' => '2026-11-10',
            'actual_completed_at' => '2026-11-10 15:00:00',
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/reports/summary')
            ->assertOk()
            ->assertJsonPath('data.projects.completed_milestone_count', $baseline['completed_milestone_count'] + 1)
            ->assertJsonPath('data.projects.on_time_milestone_count', $baseline['on_time_milestone_count'] + 1)
            ->assertJsonPath('data.projects.on_time_completion_rate', 100)
            ->assertJsonPath('data.projects.overdue_milestone_count', $baseline['overdue_milestone_count']);
    }
}
