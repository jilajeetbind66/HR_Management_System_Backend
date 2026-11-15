<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HrmsRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
    }

    public function test_employee_is_denied_sensitive_modules_and_cannot_act_for_another_employee(): void
    {
        $employee = User::where('role', 'employee')->firstOrFail();
        $otherEmployee = User::where('role', 'manager')->firstOrFail();
        Sanctum::actingAs($employee);

        $this->getJson('/api/v1/employees')->assertForbidden();
        $this->getJson('/api/v1/payroll/runs')->assertForbidden();
        $this->getJson('/api/v1/platform/audit-logs')->assertForbidden();
        $this->postJson('/api/v1/attendance/punch', [
            'employee_id' => $otherEmployee->employee_id,
        ])->assertForbidden();
        $this->postJson('/api/v1/leaves/apply', [
            'employee_id' => $otherEmployee->employee_id,
            'leave_type' => 'Casual Leave',
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-01',
            'days' => 1,
        ])->assertForbidden();
    }

    public function test_manager_sees_only_direct_reports_and_cannot_view_payroll(): void
    {
        $manager = User::where('role', 'manager')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        DB::table('employees')
            ->where('id', $employee->employee_id)
            ->update(['reporting_manager_id' => $manager->employee_id]);
        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/employees')->assertOk();
        $this->assertSame(
            [$employee->employee_id],
            array_column($response->json('data'), 'id')
        );
        $response->assertJsonMissingPath('data.0.bank_name');
        $this->getJson('/api/v1/payroll/runs')->assertForbidden();
        $this->postJson('/api/v1/employees', [])->assertForbidden();
    }

    public function test_hr_can_access_hr_operations_but_not_payroll_projects_assets_or_admin_data(): void
    {
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        Sanctum::actingAs($hrAdmin);

        $employees = $this->getJson('/api/v1/employees')->assertOk();
        $firstEmployee = $employees->json('data.0');
        $this->assertArrayHasKey('pan', $firstEmployee);
        $this->assertArrayNotHasKey('gross_monthly', $firstEmployee);
        $this->assertArrayNotHasKey('basic_monthly', $firstEmployee);
        $this->assertArrayNotHasKey('bank_name', $firstEmployee);
        $this->assertArrayNotHasKey('account_number', $firstEmployee);
        $this->assertArrayNotHasKey('ifsc_code', $firstEmployee);

        $this->getJson('/api/v1/payroll/runs')->assertForbidden();
        $this->getJson('/api/v1/projects')->assertForbidden();
        $this->getJson('/api/v1/assets')->assertForbidden();
        $this->getJson('/api/v1/platform/audit-logs')->assertForbidden();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/uploads?module=project_deliverables')->assertForbidden();

        $this->postJson('/api/v1/employees', [])->assertUnprocessable();
    }

    public function test_hr_executive_is_read_only_for_employee_onboarding(): void
    {
        $hrExecutive = User::factory()->create([
            'name' => 'HR Executive',
            'email' => 'hr.executive@example.com',
        ]);
        $hrExecutive->role = 'hr_executive';
        $hrExecutive->save();
        Sanctum::actingAs($hrExecutive);

        $this->getJson('/api/v1/employees')->assertOk();
        $this->postJson('/api/v1/employees', [])->assertForbidden();
    }

    public function test_hr_helpdesk_view_is_limited_to_hr_queries_and_their_own_tickets(): void
    {
        $admin = User::where('role', 'super_admin')->firstOrFail();
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();

        Sanctum::actingAs($admin);
        $this->postJson('/api/v1/helpdesk/tickets', [
            'title' => 'Restricted finance support ticket',
            'category' => 'Payroll',
            'priority' => 'High',
        ])->assertCreated();

        Sanctum::actingAs($hrAdmin);
        $this->postJson('/api/v1/helpdesk/tickets', [
            'title' => 'HR onboarding question',
            'category' => 'HR Query',
            'priority' => 'Medium',
        ])->assertCreated();

        $tickets = $this->getJson('/api/v1/helpdesk/tickets')->assertOk()->json('data');
        $titles = array_column($tickets, 'title');
        $this->assertContains('HR onboarding question', $titles);
        $this->assertNotContains('Restricted finance support ticket', $titles);
    }

    public function test_auditor_can_read_payroll_but_cannot_generate_or_lock_it(): void
    {
        $auditor = User::where('role', 'auditor')->firstOrFail();
        Sanctum::actingAs($auditor);

        $this->getJson('/api/v1/payroll/runs')->assertOk();
        $this->postJson('/api/v1/payroll/runs', [
            'period_year' => 2026,
            'period_month' => 12,
            'scope_key' => 'company',
            'month_name' => 'December 2026',
        ])->assertForbidden();
    }

    public function test_demo_role_account_command_creates_linked_accounts_without_resetting_existing_users(): void
    {
        $this->artisan('hrms:create-demo-role-accounts', ['--force' => true])
            ->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => 'demo.employee@pinweb.in',
            'role' => 'employee',
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'demo.manager@pinweb.in',
            'role' => 'manager',
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'demo.hr@pinweb.in',
            'role' => 'hr_admin',
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'demo.admin@pinweb.in',
            'role' => 'super_admin',
        ]);

        $demoManager = User::where('email', 'demo.manager@pinweb.in')->firstOrFail();
        $demoEmployee = User::where('email', 'demo.employee@pinweb.in')->firstOrFail();
        $this->assertDatabaseHas('employees', [
            'id' => $demoEmployee->employee_id,
            'reporting_manager_id' => $demoManager->employee_id,
        ]);
        $this->assertNotSame('password', $demoEmployee->password);

        $userCount = DB::table('users')->count();
        $this->artisan('hrms:create-demo-role-accounts', ['--force' => true])
            ->assertExitCode(1);
        $this->assertSame($userCount, DB::table('users')->count());
    }

    public function test_login_category_still_matches_the_created_role_accounts(): void
    {
        Artisan::call('hrms:create-demo-role-accounts', ['--force' => true]);
        $output = Artisan::output();
        $matched = preg_match(
            '/demo\.employee@pinweb\.in.*?([A-Za-z0-9]{32})/s',
            $output,
            $matches
        );
        $this->assertSame(1, $matched);

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'demo.employee@pinweb.in',
            'password' => $matches[1],
            'role_type' => 'employee',
        ])->assertOk()->assertJsonPath('data.user.role', 'employee');
    }
}
