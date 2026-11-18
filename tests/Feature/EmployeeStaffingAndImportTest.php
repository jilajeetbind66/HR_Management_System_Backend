<?php

namespace Tests\Feature;

use App\Mail\EmployeeAccountSetup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeStaffingAndImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
    }

    public function test_manager_request_is_reviewed_by_hr_and_approved_team_member_is_assigned(): void
    {
        $manager = User::where('role', 'manager')->firstOrFail();
        $managerEmployee = DB::table('employees')->where('id', $manager->employee_id)->first();
        $targetEmployee = DB::table('employees')
            ->join('users', 'users.employee_id', '=', 'employees.id')
            ->where('users.role', 'employee')
            ->where('employees.status', 'active')
            ->where('employees.branch_id', $managerEmployee->branch_id)
            ->whereNull('employees.deleted_at')
            ->first(['employees.id', 'employees.employee_code']);
        $this->assertNotNull($targetEmployee);

        Sanctum::actingAs($manager);
        $request = $this->postJson('/api/v1/employee-staffing-requests', [
            'request_type' => 'team_member',
            'designation' => 'Software Engineer',
            'department' => 'Engineering & Technology',
            'requested_employee_code' => $targetEmployee->employee_code,
            'justification' => 'Need additional delivery capacity for the team.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $requestId = $request->json('data.id');
        $hr = User::where('role', 'hr_admin')->firstOrFail();
        Sanctum::actingAs($hr);
        $this->patchJson("/api/v1/employee-staffing-requests/{$requestId}/review", [
            'decision' => 'approved',
            'assigned_employee_id' => $targetEmployee->id,
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.assigned_employee_id', $targetEmployee->id);

        $this->assertDatabaseHas('employees', [
            'id' => $targetEmployee->id,
            'reporting_manager_id' => $manager->employee_id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'STAFFING_REQUEST_SUBMITTED',
            'module' => 'Employees',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'STAFFING_REQUEST_REVIEWED',
            'module' => 'Employees',
        ]);
    }

    public function test_employee_csv_import_validates_then_creates_employee_account(): void
    {
        Mail::fake();
        $admin = User::where('role', 'super_admin')->firstOrFail();
        $branchId = DB::table('branches')->value('id');
        $duplicatePhone = '9876543210';
        $seedEmployeeId = DB::table('employees')->value('id');
        DB::table('employees')->where('id', $seedEmployeeId)->update(['phone' => $duplicatePhone]);
        Sanctum::actingAs($admin);

        $csv = "first_name,last_name,email,phone,branch_id,department,designation,date_of_joining\r\n"
            . "CSV,Import,csv.import@example.com,{$duplicatePhone},{$branchId},Engineering & Technology,QA Engineer,2026-11-20\r\n";

        $this->post('/api/v1/employees/import/preview', [
            'file' => UploadedFile::fake()->createWithContent('employees.csv', $csv),
        ])->assertOk()
            ->assertJsonPath('data.valid_count', 1)
            ->assertJsonPath('data.invalid_count', 0)
            ->assertJsonPath('data.rows.0.warnings.0', 'This phone number is already used by another employee. Confirm it is intentional.');

        $response = $this->post('/api/v1/employees/import', [
            'file' => UploadedFile::fake()->createWithContent('employees.csv', $csv),
        ])->assertCreated()
            ->assertJsonPath('data.imported_count', 1)
            ->assertJsonPath('data.credentials.0.setup_email_sent', true)
            ->assertJsonPath('data.setup_link_expires_in_minutes', (int) config('auth.passwords.users.expire'));

        $employeeCode = $response->json('data.credentials.0.employee_code');
        $this->assertArrayNotHasKey('temporary_password', $response->json('data.credentials.0'));
        $this->assertDatabaseHas('employees', [
            'employee_code' => $employeeCode,
            'email' => 'csv.import@example.com',
        ]);
        $this->assertDatabaseHas('users', [
            'employee_id' => DB::table('employees')->where('employee_code', $employeeCode)->value('id'),
            'email' => 'csv.import@example.com',
            'must_change_password' => true,
        ]);
        $setupMail = Mail::sent(EmployeeAccountSetup::class)->first();
        $this->assertNotNull($setupMail);
        $this->assertTrue($setupMail->hasTo('csv.import@example.com'));
        $this->assertStringStartsWith('http://localhost:3000/setup-password?', $setupMail->setupUrl);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'EMPLOYEES_BULK_IMPORTED',
            'module' => 'Employees',
        ]);
    }

    public function test_csv_with_duplicate_emails_reports_both_rows_and_imports_neither(): void
    {
        $admin = User::where('role', 'super_admin')->firstOrFail();
        $branchId = DB::table('branches')->value('id');
        Sanctum::actingAs($admin);
        $csv = "first_name,last_name,email,branch_id,department,designation,date_of_joining\r\n"
            . "First,Duplicate,duplicate@example.com,{$branchId},Engineering,Engineer,2026-11-20\r\n"
            . "Second,Duplicate,duplicate@example.com,{$branchId},Engineering,Engineer,2026-11-20\r\n";

        $this->post('/api/v1/employees/import/preview', [
            'file' => UploadedFile::fake()->createWithContent('duplicates.csv', $csv),
        ])->assertOk()
            ->assertJsonPath('data.valid_count', 0)
            ->assertJsonPath('data.invalid_count', 2);

        $this->post('/api/v1/employees/import', [
            'file' => UploadedFile::fake()->createWithContent('duplicates.csv', $csv),
        ])->assertUnprocessable();

        $this->assertDatabaseMissing('employees', ['email' => 'duplicate@example.com']);
    }

    public function test_staffing_and_csv_routes_enforce_role_boundaries(): void
    {
        $manager = User::where('role', 'manager')->firstOrFail();
        Sanctum::actingAs($manager);
        $requestId = $this->postJson('/api/v1/employee-staffing-requests', [
            'request_type' => 'new_hire',
            'designation' => 'Engineer',
            'department' => 'Engineering',
            'justification' => 'Additional team capacity is required.',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/employee-staffing-requests/{$requestId}/review", [
            'decision' => 'approved',
        ])->assertForbidden();
        $this->post('/api/v1/employees/import/preview', [
            'file' => UploadedFile::fake()->createWithContent('employees.csv', "first_name,last_name,email,branch_id,department,designation,date_of_joining\n"),
        ])->assertForbidden();

        Sanctum::actingAs(User::where('role', 'employee')->firstOrFail());
        $this->getJson('/api/v1/employee-staffing-requests')->assertForbidden();
        $this->postJson('/api/v1/employee-staffing-requests', [])->assertForbidden();
    }
}
