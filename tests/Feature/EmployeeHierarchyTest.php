<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeHierarchyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
    }

    public function test_super_admin_can_update_employee_manager_and_role_with_audit_log(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $employee = User::where('employee_id', DB::table('employees')
            ->where('employee_code', 'EMP-009')
            ->value('id'))->firstOrFail();
        $manager = User::where('role', 'manager')->firstOrFail();
        $initialManagerId = DB::table('employees')
            ->where('id', $employee->employee_id)
            ->value('reporting_manager_id');
        Sanctum::actingAs($superAdmin);

        $this->patchJson("/api/v1/employees/{$employee->employee_id}/hierarchy", [
            'reporting_manager_id' => $manager->employee_id,
            'role' => 'hr_admin',
            'version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.reporting_manager_name', $manager->name)
            ->assertJsonPath('data.role', 'hr_admin')
            ->assertJsonPath('data.version', 2);

        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'role' => 'hr_admin',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'EMPLOYEE_HIERARCHY_UPDATED',
            'module' => 'Employees',
            'actor' => $superAdmin->name,
        ]);
    }

    public function test_employee_profile_edit_updates_login_identity_and_audits_changed_fields(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        $profile = DB::table('employees')->where('id', $employee->employee_id)->first();
        Sanctum::actingAs($superAdmin);

        $response = $this->patchJson("/api/v1/employees/{$employee->employee_id}/hierarchy", [
            'first_name' => 'Updated',
            'last_name' => 'Employee',
            'email' => 'updated.employee@example.com',
            'phone' => '9876543210',
            'gender' => $profile->gender,
            'date_of_birth' => $profile->date_of_birth,
            'branch_id' => $profile->branch_id,
            'department_id' => $profile->department_id,
            'department' => $profile->department,
            'designation_id' => $profile->designation_id,
            'designation' => $profile->designation,
            'date_of_joining' => $profile->date_of_joining,
            'employment_type' => $profile->employment_type,
            'reporting_manager_id' => $profile->reporting_manager_id,
            'role' => 'employee',
            'version' => $profile->version,
        ])->assertOk()
            ->assertJsonPath('data.first_name', 'Updated')
            ->assertJsonPath('data.email', 'updated.employee@example.com');

        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'name' => 'Updated Employee',
            'email' => 'updated.employee@example.com',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'EMPLOYEE_PROFILE_UPDATED',
            'module' => 'Employees',
        ]);
        $this->assertGreaterThan((int) $profile->version, (int) $response->json('data.version'));
    }

    public function test_employee_profile_edit_rejects_an_email_owned_by_another_account(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        $profile = DB::table('employees')->where('id', $employee->employee_id)->first();
        Sanctum::actingAs($superAdmin);

        $this->patchJson("/api/v1/employees/{$employee->employee_id}/hierarchy", [
            'reporting_manager_id' => $profile->reporting_manager_id,
            'role' => 'employee',
            'email' => $superAdmin->email,
            'version' => $profile->version,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_hr_admin_can_assign_an_allowed_employee_role(): void
    {
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        $employee = User::where('employee_id', DB::table('employees')
            ->where('employee_code', 'EMP-009')
            ->value('id'))->firstOrFail();
        $manager = User::where('role', 'manager')->firstOrFail();
        Sanctum::actingAs($hrAdmin);

        $this->patchJson("/api/v1/employees/{$employee->employee_id}/hierarchy", [
            'reporting_manager_id' => $manager->employee_id,
            'role' => 'manager',
            'version' => 1,
        ])->assertOk()
            ->assertJsonPath('data.role', 'manager');
    }

    public function test_hr_admin_cannot_assign_a_privileged_role(): void
    {
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        Sanctum::actingAs($hrAdmin);

        $this->patchJson("/api/v1/employees/{$employee->employee_id}/hierarchy", [
            'reporting_manager_id' => null,
            'role' => 'payroll_admin',
            'version' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_cross_branch_managers_are_blocked_by_default_and_can_be_enabled(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $employee = User::where('employee_id', DB::table('employees')
            ->where('employee_code', 'EMP-005')
            ->value('id'))->firstOrFail();
        $manager = User::where('employee_id', DB::table('employees')
            ->where('employee_code', 'EMP-004')
            ->value('id'))->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $payload = [
            'reporting_manager_id' => $manager->employee_id,
            'role' => 'employee',
            'version' => 1,
        ];
        $endpoint = "/api/v1/employees/{$employee->employee_id}/hierarchy";

        $this->patchJson($endpoint, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reporting_manager_id');

        config(['hrms.allow_cross_branch_managers' => true]);
        $this->patchJson($endpoint, $payload)
            ->assertOk()
            ->assertJsonPath('data.reporting_manager_id', $manager->employee_id)
            ->assertJsonPath('data.allow_cross_branch_managers', true);
    }

    public function test_self_assignment_and_circular_reporting_chains_are_rejected(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        $manager = User::where('role', 'manager')->firstOrFail();
        Sanctum::actingAs($superAdmin);

        $endpoint = "/api/v1/employees/{$employee->employee_id}/hierarchy";
        $this->patchJson($endpoint, [
            'reporting_manager_id' => $employee->employee_id,
            'role' => 'employee',
            'version' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('reporting_manager_id');

        DB::table('employees')
            ->where('id', $manager->employee_id)
            ->update(['reporting_manager_id' => $employee->employee_id]);

        $this->patchJson($endpoint, [
            'reporting_manager_id' => $manager->employee_id,
            'role' => 'employee',
            'version' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('reporting_manager_id');
    }

    public function test_stale_employee_profile_version_returns_conflict_without_changes(): void
    {
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        $manager = User::where('role', 'manager')->firstOrFail();
        $initialManagerId = DB::table('employees')
            ->where('id', $employee->employee_id)
            ->value('reporting_manager_id');
        Sanctum::actingAs($superAdmin);

        $this->patchJson("/api/v1/employees/{$employee->employee_id}/hierarchy", [
            'reporting_manager_id' => $manager->employee_id,
            'role' => 'manager',
            'version' => 2,
        ])->assertStatus(409)
            ->assertJsonPath('code', 'STALE_EMPLOYEE_PROFILE');

        $this->assertDatabaseHas('users', [
            'id' => $employee->id,
            'role' => 'employee',
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $employee->employee_id,
            'reporting_manager_id' => $initialManagerId,
            'version' => 1,
        ]);
    }

    public function test_hr_executive_cannot_edit_employee_hierarchy_or_role(): void
    {
        $hrExecutive = User::factory()->create([
            'name' => 'HR Executive',
            'email' => 'hr.executive@example.com',
        ]);
        $hrExecutive->role = 'hr_executive';
        $hrExecutive->save();
        $employee = User::where('role', 'employee')->firstOrFail();
        Sanctum::actingAs($hrExecutive);

        $this->patchJson("/api/v1/employees/{$employee->employee_id}/hierarchy", [
            'reporting_manager_id' => null,
            'role' => 'employee',
            'version' => 1,
        ])->assertForbidden();
    }
}
