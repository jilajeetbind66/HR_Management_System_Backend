<?php

namespace Tests\Feature;

use App\Mail\EmployeeAccountSetup;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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

    public function test_new_employee_receives_a_one_time_password_setup_link(): void
    {
        Mail::fake();
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
            ->assertJsonPath('credentials.setup_email_sent', true)
            ->assertJsonPath('credentials.must_change_password', true);

        $employee = $response->json('data');
        $user = User::where('employee_id', $employee['id'])->firstOrFail();

        $this->assertSame('employee', $user->role);
        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->email_verified_at);
        $this->assertSame($employee['employee_code'], $response->json('credentials.employee_code'));
        $this->assertArrayNotHasKey('temporary_password', $response->json('credentials'));

        $setupMail = Mail::sent(EmployeeAccountSetup::class)->first();
        $this->assertNotNull($setupMail);
        $this->assertTrue($setupMail->hasTo('aman.kumar@example.com'));
        $this->assertStringStartsWith('http://localhost:3000/setup-password?', $setupMail->setupUrl);
        parse_str((string) parse_url($setupMail->setupUrl, PHP_URL_QUERY), $setupQuery);
        $setupToken = $setupQuery['token'] ?? '';
        $this->assertNotSame('', $setupToken);

        $this->postJson('/api/v1/auth/complete-account-setup', [
            'email' => 'aman.kumar@example.com',
            'token' => $setupToken,
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->postJson('/api/v1/auth/complete-account-setup', [
            'email' => 'aman.kumar@example.com',
            'token' => $setupToken,
            'password' => 'AmanNewPassword!2026',
            'password_confirmation' => 'AmanNewPassword!2026',
        ])->assertOk()
            ->assertJsonPath('message', 'Your password is set. You can now sign in to Pinweb HRMS.');

        $this->assertTrue(Hash::check('AmanNewPassword!2026', $user->fresh()->password));
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertFalse($user->fresh()->must_change_password);

        $this->postJson('/api/v1/auth/complete-account-setup', [
            'email' => 'aman.kumar@example.com',
            'token' => $setupToken,
            'password' => 'AmanNewPassword!2026',
            'password_confirmation' => 'AmanNewPassword!2026',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'This password setup link is invalid, expired, or already used. Ask HR to send a new one.');

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $employee['employee_code'],
            'password' => 'AmanNewPassword!2026',
            'role_type' => 'employee',
        ])->assertOk();
    }

    public function test_probation_status_is_derived_from_joining_date_inclusive_of_six_month_boundary(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-14 12:00:00'));

        try {
            Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
            $baseInput = [
                'first_name' => 'Probation',
                'last_name' => 'Test',
                'branch_id' => DB::table('branches')->value('id'),
                'designation' => 'Software Engineer',
                'department' => 'Engineering & Technology',
            ];

            $probationResponse = $this->postJson('/api/v1/employees', [
                ...$baseInput,
                'email' => 'probation.test@example.com',
                'date_of_joining' => '2026-06-01',
            ])->assertCreated()
                ->assertJsonPath('data.probation_status', 'Probation (6 Mo)');

            $confirmedResponse = $this->postJson('/api/v1/employees', [
                ...$baseInput,
                'email' => 'confirmed.test@example.com',
                'date_of_joining' => '2026-05-14',
            ])->assertCreated()
                ->assertJsonPath('data.probation_status', 'Confirmed');

            $probationEmployeeId = $probationResponse->json('data.id');
            DB::table('employees')->where('id', $probationEmployeeId)->update([
                'probation_status' => 'Confirmed',
            ]);

            $employeeList = $this->getJson('/api/v1/employees')->assertOk();
            $probationEmployee = collect($employeeList->json('data'))
                ->firstWhere('id', $probationEmployeeId);
            $this->assertSame('Probation (6 Mo)', $probationEmployee['probation_status']);
        } finally {
            Carbon::setTestNow();
        }
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
            ->assertJsonPath('credentials.must_change_password', true);

        $employeeId = $response->json('data.id');
        $this->assertDatabaseHas('users', [
            'employee_id' => $employeeId,
            'role' => 'hr_executive',
            'must_change_password' => true,
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

    public function test_employee_creation_resolves_organization_ids_and_names_from_master_records(): void
    {
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $department = DB::table('departments')
            ->where('name', 'Engineering & Technology')
            ->first();
        $designation = DB::table('designations')
            ->where('department_id', $department->id)
            ->where('title', 'Director of Engineering')
            ->first();

        $response = $this->postJson('/api/v1/employees', [
            'first_name' => 'Master',
            'last_name' => 'Record',
            'email' => 'master.record@example.com',
            'branch_id' => DB::table('branches')->value('id'),
            'department_id' => $department->id,
            'designation_id' => $designation->id,
            'date_of_joining' => now()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.department_id', $department->id)
            ->assertJsonPath('data.department', $department->name)
            ->assertJsonPath('data.designation_id', $designation->id)
            ->assertJsonPath('data.designation', $designation->title);

        $this->assertDatabaseHas('employees', [
            'id' => $response->json('data.id'),
            'department_id' => $department->id,
            'designation_id' => $designation->id,
        ]);
    }

    public function test_employee_gender_uses_allowed_values_and_explicit_other_default(): void
    {
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $employeeInput = [
            'first_name' => 'Gender',
            'last_name' => 'Validation',
            'email' => 'gender.validation@example.com',
            'branch_id' => DB::table('branches')->value('id'),
            'department' => 'Engineering & Technology',
            'designation' => 'Software Engineer',
            'date_of_joining' => now()->toDateString(),
        ];

        $this->postJson('/api/v1/employees', [
            ...$employeeInput,
            'gender' => 'Unknown',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('gender');

        $response = $this->postJson('/api/v1/employees', $employeeInput)
            ->assertCreated()
            ->assertJsonPath('data.gender', 'Other');

        $this->assertDatabaseHas('employees', [
            'id' => $response->json('data.id'),
            'gender' => 'Other',
        ]);
    }

}
