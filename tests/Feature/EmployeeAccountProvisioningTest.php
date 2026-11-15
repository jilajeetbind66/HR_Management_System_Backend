<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeAccountProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
    }

    public function test_new_employee_can_access_the_portal_immediately_and_change_password_from_account_security(): void
    {
        $admin = User::where('role', 'super_admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/employees', [
            'first_name' => 'Aman',
            'last_name' => 'Kumar',
            'email' => 'AMAN.KUMAR@EXAMPLE.COM',
            'branch_id' => DB::table('branches')->value('id'),
            'designation' => 'Software Engineer',
            'department' => 'Engineering & Technology',
            'date_of_joining' => '2026-11-14',
        ])->assertCreated()
            ->assertJsonPath('data.email', 'aman.kumar@example.com')
            ->assertJsonPath('credentials.login_identifier', 'aman.kumar@example.com')
            ->assertJsonPath('credentials.must_change_password', false);

        $employee = $response->json('data');
        $temporaryPassword = $response->json('credentials.temporary_password');
        $user = User::where('employee_id', $employee['id'])->firstOrFail();

        $this->assertSame('employee', $user->role);
        $this->assertFalse($user->must_change_password);
        $this->assertGreaterThanOrEqual(12, strlen($temporaryPassword));
        $this->assertTrue(Hash::check($temporaryPassword, $user->password));
        $this->assertSame($employee['employee_code'], $response->json('credentials.employee_code'));

        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/v1/auth/login', [
            'identifier' => $user->email,
            'password' => $temporaryPassword,
            'role_type' => 'employee',
        ])->assertOk()
            ->assertJsonPath('data.user.must_change_password', false);
        $token = $login->json('data.token');

        $this->withToken($token)->getJson('/api/v1/attendance')
            ->assertOk();

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => 'incorrect-temporary-password',
            'password' => 'AmanNewPassword!2026',
            'password_confirmation' => 'AmanNewPassword!2026',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->withToken($token)->postJson('/api/v1/auth/change-password', [
            'current_password' => $temporaryPassword,
            'password' => 'AmanNewPassword!2026',
            'password_confirmation' => 'AmanNewPassword!2026',
        ])->assertOk()
            ->assertJsonPath('data.must_change_password', false);

        $this->assertFalse($user->fresh()->must_change_password);
        $this->withToken($token)->getJson('/api/v1/attendance')->assertOk();
        $this->postJson('/api/v1/auth/login', [
            'identifier' => $employee['employee_code'],
            'password' => 'AmanNewPassword!2026',
            'role_type' => 'employee',
        ])->assertOk();
    }

    public function test_separate_provisioning_endpoints_for_existing_employee_records_are_unavailable(): void
    {
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $this->getJson('/api/v1/admin/users/unprovisioned-employees')->assertNotFound();
        $this->postJson('/api/v1/admin/users/employees/999999/provision')->assertNotFound();
    }

    public function test_legacy_accounts_marked_for_first_login_change_are_not_blocked(): void
    {
        $employee = User::where('role', 'employee')->firstOrFail();
        $employee->must_change_password = true;
        $employee->save();
        $token = $employee->createToken('legacy-first-login-account')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/attendance')->assertOk();
    }

    public function test_super_admin_can_assign_a_role_when_creating_an_employee(): void
    {
        $admin = User::where('role', 'super_admin')->firstOrFail();
        Sanctum::actingAs($admin);

        $employeeInput = [
            'first_name' => 'Neha',
            'last_name' => 'Gupta',
            'email' => 'neha.gupta@example.com',
            'role' => 'hr_executive',
            'phone' => '98765432101',
            'branch_id' => DB::table('branches')->value('id'),
            'designation' => 'HR Executive',
            'department' => 'Human Resources',
            'date_of_joining' => '2026-11-14',
        ];

        $this->postJson('/api/v1/employees', $employeeInput)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');
        $this->assertDatabaseMissing('employees', ['email' => 'neha.gupta@example.com']);

        $employeeInput['phone'] = '9876543210';
        $response = $this->postJson('/api/v1/employees', $employeeInput)
            ->assertCreated()
            ->assertJsonPath('credentials.role', 'hr_executive')
            ->assertJsonPath('credentials.must_change_password', false);

        $employeeId = $response->json('data.id');
        $this->assertDatabaseHas('users', [
            'employee_id' => $employeeId,
            'role' => 'hr_executive',
            'must_change_password' => false,
        ]);
    }

    public function test_hr_admin_cannot_assign_a_privileged_role_when_creating_an_employee(): void
    {
        Sanctum::actingAs(User::where('role', 'hr_admin')->firstOrFail());

        $this->postJson('/api/v1/employees', [
            'first_name' => 'Neha',
            'last_name' => 'Gupta',
            'email' => 'neha.privileged@example.com',
            'role' => 'payroll_admin',
            'branch_id' => DB::table('branches')->value('id'),
            'designation' => 'Payroll Lead',
            'department' => 'Finance',
            'date_of_joining' => '2026-11-14',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('role');

        $this->assertDatabaseMissing('employees', ['email' => 'neha.privileged@example.com']);
    }

}
