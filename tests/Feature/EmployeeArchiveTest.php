<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
    }

    public function test_archiving_employee_retains_record_hides_it_and_revokes_login_access(): void
    {
        $admin = User::where('role', 'super_admin')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        $employeeToken = $employee->createToken('employee-archive-test')->plainTextToken;
        Sanctum::actingAs($admin);

        $impact = $this->getJson("/api/v1/employees/{$employee->employee_id}/archive-impact")
            ->assertOk()
            ->json('data');
        $replacementEmployeeId = DB::table('employees')
            ->join('users', 'users.employee_id', '=', 'employees.id')
            ->where('employees.id', '<>', $employee->employee_id)
            ->where('employees.status', 'active')
            ->whereNull('employees.deleted_at')
            ->whereIn('users.role', ['manager', 'hr_admin', 'hr_executive', 'super_admin'])
            ->value('employees.id');

        $this->deleteJson("/api/v1/employees/{$employee->employee_id}", [
            'reporting_manager_id' => $impact['direct_reports'] !== [] ? $replacementEmployeeId : null,
            'reassign_project_employee_id' => $impact['open_projects'] !== [] ? $replacementEmployeeId : null,
            'cancel_pending_requests' => true,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Employee archived successfully.');

        $this->assertDatabaseHas('employees', [
            'id' => $employee->employee_id,
            'is_demo' => false,
        ]);
        $this->assertNotNull(DB::table('employees')
            ->where('id', $employee->employee_id)
            ->value('deleted_at'));
        $this->assertDatabaseHas('employees', [
            'id' => $employee->employee_id,
            'status' => 'inactive',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'EMPLOYEE_ARCHIVED',
            'module' => 'Employees',
        ]);

        $directory = $this->getJson('/api/v1/employees')->assertOk()->json('data');
        $this->assertFalse(collect($directory)->contains(
            fn (array $record): bool => (int) $record['id'] === (int) $employee->employee_id
        ));

        $this->app['auth']->forgetGuards();
        $this->withToken($employeeToken)->getJson('/api/v1/attendance')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', [
            'identifier' => $employee->email,
            'password' => 'AdminPass@2026#Secure',
            'role_type' => 'employee',
        ])->assertUnauthorized();
    }

    public function test_hr_admin_cannot_archive_a_privileged_system_role(): void
    {
        Sanctum::actingAs(User::where('role', 'hr_admin')->firstOrFail());
        $payrollAdmin = User::where('role', 'payroll_admin')->firstOrFail();

        $this->deleteJson("/api/v1/employees/{$payrollAdmin->employee_id}")
            ->assertForbidden();

        $this->assertDatabaseHas('employees', [
            'id' => $payrollAdmin->employee_id,
            'deleted_at' => null,
        ]);
    }

    public function test_employee_cannot_archive_own_record(): void
    {
        $employee = User::where('role', 'employee')->firstOrFail();
        Sanctum::actingAs($employee);

        $this->deleteJson("/api/v1/employees/{$employee->employee_id}")
            ->assertForbidden();
    }
}
