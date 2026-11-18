<?php

namespace Tests\Feature;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use App\Models\User;

class HrmsArchitectureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Run bootstrap installer for test environment
        Artisan::call('hrms:install', ['--force' => true]);
        Sanctum::actingAs(User::where('role', 'super_admin')->first());
    }

    /**
     * Test Health Endpoint
     */
    public function test_health_check_endpoint(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'healthy',
                'version' => '1.2',
                'company' => 'Pinweb Pvt. Ltd.'
            ]);
    }

    public function test_login_issues_token_and_private_upload_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/uploads', [])
            ->assertUnauthorized();

        $login = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'rajesh.sharma@pinweb.in',
            'password' => 'AdminPass@2026#Secure',
            'role_type' => 'admin',
        ]);

        $login->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['id', 'user_id', 'role']]]);

        $this->withToken($login->json('data.token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role', 'super_admin');
    }

    public function test_logout_revokes_the_current_sanctum_token(): void
    {
        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/v1/auth/login', [
            'identifier' => 'rajesh.sharma@pinweb.in',
            'password' => 'AdminPass@2026#Secure',
        ])->assertOk();

        $this->withToken($login->json('data.token'))
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJson(['message' => 'Logged out successfully.']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_accepts_employee_code_and_rejects_mismatched_role_selection(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'EMP-001',
            'password' => 'AdminPass@2026#Secure',
            'role_type' => 'admin',
        ])->assertOk()->assertJsonPath('data.user.role', 'super_admin');

        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'EMP-001',
            'password' => 'AdminPass@2026#Secure',
            'role_type' => 'employee',
        ])->assertUnauthorized();
    }

    public function test_employee_cannot_upload_a_receipt_for_another_employee(): void
    {
        $employeeUser = User::where('role', 'employee')->first();
        $otherEmployee = DB::table('employees')
            ->where('id', '!=', $employeeUser->employee_id)
            ->first();
        $claimId = DB::table('expense_claims')->insertGetId([
            'employee_id' => $otherEmployee->id,
            'category' => 'Travel',
            'amount' => 100,
            'incurred_on' => '2026-11-14',
            'description' => 'Another employee expense claim.',
            'status' => 'submitted',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Sanctum::actingAs($employeeUser);

        $this->post('/api/v1/uploads', [
            'module' => 'expense_receipts',
            'employee_id' => $otherEmployee->id,
            'record_id' => $claimId,
            'file' => UploadedFile::fake()->create('receipt.pdf', 10, 'application/pdf'),
        ])->assertForbidden();
    }

    public function test_upload_reports_missing_cloudinary_configuration(): void
    {
        Config::set('services.cloudinary.cloud_name', null);
        Config::set('services.cloudinary.api_key', null);
        Config::set('services.cloudinary.api_secret', null);
        $employeeUser = User::where('role', 'employee')->first();
        Sanctum::actingAs($employeeUser);

        $this->post('/api/v1/uploads', [
            'module' => 'employee_documents',
            'employee_id' => (string) $employeeUser->employee_id,
            'document_type' => 'Identity document',
            'file' => UploadedFile::fake()->create('identity.pdf', 10, 'application/pdf'),
        ])->assertStatus(503)
            ->assertJsonPath('code', 'CLOUDINARY_NOT_CONFIGURED');
    }

    public function test_clean_operational_data_preserves_masters_and_super_admin(): void
    {
        $admin = User::where('role', 'super_admin')->firstOrFail();
        $employeeId = $admin->employee_id;
        $now = now();
        $courseId = DB::table('training_courses')->insertGetId([
            'title' => 'Cleanup test course',
            'category' => 'Compliance',
            'mandatory' => true,
            'status' => 'active',
            'created_by_user_id' => $admin->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('training_enrollments')->insert([
            'course_id' => $courseId,
            'employee_id' => $employeeId,
            'assigned_by_user_id' => $admin->id,
            'status' => 'assigned',
            'progress_percent' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $caseId = DB::table('employee_lifecycle_cases')->insertGetId([
            'employee_id' => $employeeId,
            'case_type' => 'onboarding',
            'effective_date' => '2027-01-01',
            'status' => 'active',
            'created_by_user_id' => $admin->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('employee_lifecycle_tasks')->insert([
            'lifecycle_case_id' => $caseId,
            'department' => 'HR',
            'title' => 'Cleanup test task',
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('expense_claims')->insert([
            'employee_id' => $employeeId,
            'category' => 'Travel',
            'amount' => 100,
            'incurred_on' => '2026-11-14',
            'description' => 'Cleanup test claim',
            'status' => 'submitted',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('loan_requests')->insert([
            'employee_id' => $employeeId,
            'requested_amount' => 500,
            'purpose' => 'Cleanup test request',
            'status' => 'submitted',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $policyId = DB::table('company_policies')->insertGetId([
            'title' => 'Cleanup test policy',
            'category' => 'Other',
            'version' => '1.0',
            'effective_date' => '2026-01-01',
            'mandatory' => true,
            'content' => 'Cleanup test content.',
            'status' => 'active',
            'created_by_user_id' => $admin->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('policy_acknowledgements')->insert([
            'company_policy_id' => $policyId,
            'employee_id' => $employeeId,
            'policy_version' => '1.0',
            'acknowledged_by_user_id' => $admin->id,
            'acknowledged_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('hr_letters')->insert([
            'letter_number' => 'HRL-CLEANUP-001',
            'employee_id' => $employeeId,
            'letter_type' => 'experience',
            'status' => 'draft',
            'snapshot' => json_encode(['employee_code' => 'cleanup']),
            'created_by_user_id' => $admin->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $uploadId = DB::table('hrms_uploads')->insertGetId([
            'module' => 'attendance_csv',
            'uploaded_by_user_id' => $admin->id,
            'original_filename' => 'cleanup-attendance.csv',
            'cloudinary_public_id' => 'cleanup-attendance-csv',
            'cloudinary_resource_type' => 'raw',
            'cloudinary_delivery_type' => 'authenticated',
            'cloudinary_format' => 'csv',
            'mime_type' => 'text/csv',
            'file_size' => 100,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $attendanceImportId = DB::table('attendance_csv_imports')->insertGetId([
            'upload_id' => $uploadId,
            'processed_by_user_id' => $admin->id,
            'status' => 'processed',
            'row_count' => 1,
            'attendance_day_count' => 1,
            'imported_count' => 1,
            'summary' => json_encode(['status' => 'processed']),
            'processed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('attendance_records')->insert([
            'employee_id' => $employeeId,
            'attendance_csv_import_id' => $attendanceImportId,
            'date' => '2026-11-14',
            'punch_in' => '09:00 AM',
            'punch_out' => null,
            'status' => 'Present',
            'work_hours' => 0,
            'location' => 'Biometric CSV Import',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->artisan('hrms:clean-operational-data', [
            '--database' => ':memory:',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseCount('branches', 2);
        $this->assertDatabaseCount('departments', 3);
        $this->assertDatabaseHas('departments', ['name' => 'Finance & Payroll']);
        $this->assertDatabaseCount('designations', 3);
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseCount('attendance_csv_imports', 0);
        $this->assertDatabaseCount('hrms_uploads', 0);
        $this->assertDatabaseCount('employee_statutory_details', 0);
        $this->assertDatabaseCount('training_enrollments', 0);
        $this->assertDatabaseCount('training_courses', 0);
        $this->assertDatabaseCount('employee_lifecycle_tasks', 0);
        $this->assertDatabaseCount('employee_lifecycle_cases', 0);
        $this->assertDatabaseCount('loan_requests', 0);
        $this->assertDatabaseCount('expense_claims', 0);
        $this->assertDatabaseCount('company_policies', 0);
        $this->assertDatabaseCount('policy_acknowledgements', 0);
        $this->assertDatabaseCount('hr_letters', 0);
        $this->assertDatabaseHas('users', ['role' => 'super_admin']);
    }

    /**
     * Test Company Organization Master (Section 1 & 5)
     */
    public function test_organization_company_master(): void
    {
        $response = $this->getJson('/api/v1/organization/company');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'id', 'name', 'legal_name', 'pan', 'registered_state', 'currency'
                ]
            ]);
    }

    public function test_organization_branches_include_employee_counts(): void
    {
        $branch = DB::table('branches')->orderBy('id')->first();
        $expectedCount = DB::table('employees')->where('branch_id', $branch->id)->count();

        $response = $this->getJson('/api/v1/organization/branches')
            ->assertOk();
        $branchData = collect($response->json('data'))->firstWhere('id', $branch->id);

        $this->assertNotNull($branchData);
        $this->assertSame($expectedCount, (int) $branchData['employees_count']);
    }

    /**
     * Test M17 Project Creation & Assignment Workflow (Section 9)
     */
    public function test_m17_project_assignment_and_progress_flow(): void
    {
        $employee = DB::table('employees')->first();

        // 1. Create Project
        $createResponse = $this->postJson('/api/v1/projects', [
            'name' => 'Automated EPF ECR Return Filing API',
            'description' => 'Generate monthly EPFO electronic text file',
            'deliverable' => '100% compliant txt parser with UAN validator',
            'priority' => 'High',
            'assigned_employee_id' => $employee->id,
            'start_date' => '2026-11-01',
            'planned_end_date' => '2026-11-20',
            'created_by' => 'Priya Nair'
        ]);

        $createResponse->assertStatus(201);
        $projectData = $createResponse->json('data');
        $this->assertEquals('Automated EPF ECR Return Filing API', $projectData['name']);

        // 2. Fetch projects
        $listResponse = $this->getJson('/api/v1/projects');
        $listResponse->assertStatus(200);
        $assignment = DB::table('project_assignments')->where('project_id', $projectData['id'])->first();
        $this->assertNotNull($assignment);

        // 3. Update Progress
        $progressResponse = $this->postJson("/api/v1/project-assignments/{$assignment->id}/progress", [
            'progress_percent' => 60,
            'note' => 'Built EPFO txt format parser with sample test cases',
            'updated_by' => 'Aarav Patel',
            'version' => 1
        ]);
        $progressResponse->assertStatus(200);

        // 4. Submit Completion
        $submitResponse = $this->postJson("/api/v1/project-assignments/{$assignment->id}/submit", [
            'submission_note' => 'Delivered on time with unit tests passing',
            'deliverable_link' => 'https://github.com/pinweb/epfo-parser/pull/1'
        ]);
        $submitResponse->assertStatus(200);

        // 5. HR Accept Completion
        $acceptResponse = $this->postJson("/api/v1/project-assignments/{$assignment->id}/accept", [
            'accepted_by' => 'Priya Nair (HR Admin)'
        ]);
        $acceptResponse->assertStatus(200);

        $finalAssignment = DB::table('project_assignments')->where('id', $assignment->id)->first();
        $this->assertEquals('completed', $finalAssignment->status);
        $this->assertNotNull($finalAssignment->actual_completed_at);
    }

    /**
     * Test Section 7.2 Duplicate Payroll Run Guard
     */
    public function test_payroll_unique_period_and_scope_guard(): void
    {
        // 1. First run
        $first = $this->postJson('/api/v1/payroll/runs', [
            'period_year' => 2026,
            'period_month' => 9,
            'scope_key' => 'company',
            'month_name' => 'September 2026'
        ]);
        $first->assertStatus(201);

        // 2. Duplicate run with same period and scope -> Must return 409 DUPLICATE_PAYROLL_SCOPE
        $duplicate = $this->postJson('/api/v1/payroll/runs', [
            'period_year' => 2026,
            'period_month' => 9,
            'scope_key' => 'company',
            'month_name' => 'September 2026'
        ]);

        $duplicate->assertStatus(409)
            ->assertJson([
                'code' => 'DUPLICATE_PAYROLL_SCOPE'
            ]);
    }

    /**
     * Test Employees, Attendance, and Leave APIs
     */
    public function test_employees_attendance_and_leaves_api(): void
    {
        $response = $this->getJson('/api/v1/employees');
        $response->assertStatus(200)->assertJsonStructure(['data' => [['id', 'employee_code', 'first_name', 'email']]]);

        $employee = DB::table('employees')->first();
        $punchRes = $this->postJson('/api/v1/attendance/punch', [
            'employee_id' => $employee->id,
            'location' => 'Prayagraj Branch'
        ]);
        $punchRes->assertStatus(200)->assertJsonStructure(['type', 'data']);

        $leaveRes = $this->postJson('/api/v1/leaves/apply', [
            'employee_id' => $employee->id,
            'leave_type' => 'Casual Leave (CL)',
            'from_date' => '2026-12-01',
            'to_date' => '2026-12-02',
            'days' => 2,
            'reason' => 'Annual personal leave'
        ]);
        $leaveRes->assertStatus(201)->assertJsonStructure(['data' => ['id', 'status']]);
    }

    public function test_regularization_is_stored_and_updates_attendance_when_approved(): void
    {
        $employee = User::where('role', 'employee')->firstOrFail();
        $manager = User::where('role', 'manager')->firstOrFail();
        DB::table('employees')
            ->where('id', $employee->employee_id)
            ->update(['reporting_manager_id' => $manager->employee_id]);

        Sanctum::actingAs($employee);
        $request = $this->postJson('/api/v1/attendance/regularizations', [
            'date' => '2026-12-01',
            'punch_in_requested' => '09:00 AM',
            'punch_out_requested' => '05:30 PM',
            'reason' => 'Forgot to punch out',
        ])->assertCreated();
        $requestId = $request->json('data.id');

        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/attendance/regularizations/{$requestId}/review", [
            'status' => 'approved',
        ])->assertOk();

        $this->assertDatabaseHas('attendance_regularization_requests', [
            'id' => $requestId,
            'status' => 'approved',
            'approved_by' => $manager->name,
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->employee_id,
            'date' => '2026-12-01',
            'punch_in' => '09:00 AM',
            'punch_out' => '05:30 PM',
            'work_hours' => 8.5,
        ]);
    }

    public function test_helpdesk_and_announcement_writes_are_persisted(): void
    {
        $ticket = $this->postJson('/api/v1/helpdesk/tickets', [
            'title' => 'VPN access request',
            'category' => 'IT Support',
            'priority' => 'High',
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('helpdesk_tickets', [
            'id' => $ticket['id'],
            'title' => 'VPN access request',
            'created_by_user_id' => User::where('role', 'super_admin')->value('id'),
            'sla_hours_left' => 8,
        ]);
        $this->getJson('/api/v1/helpdesk/tickets')
            ->assertOk()
            ->assertJsonPath('data.0.id', $ticket['id']);

        $announcement = $this->postJson('/api/v1/announcements', [
            'title' => 'Office closure notice',
            'content' => 'The office will be closed on the scheduled holiday.',
            'category' => 'General',
            'target' => 'All Company',
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('announcements', [
            'id' => $announcement['id'],
            'title' => 'Office closure notice',
            'published_by' => User::where('role', 'super_admin')->value('name'),
        ]);
        $this->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonPath('data.0.id', $announcement['id']);
    }

    public function test_recruitment_stage_and_candidate_conversion_are_persisted_without_mock_pay_data(): void
    {
        $job = $this->postJson('/api/v1/recruitment/jobs', [
            'title' => 'Backend Engineer',
            'department' => 'Engineering',
            'branch_id' => 1,
            'vacancies' => 1,
            'experience' => '3-5 Years',
        ])->assertCreated()->json('data');

        $this->postJson('/api/v1/recruitment/candidates', [
            'job_id' => $job['id'],
            'first_name' => 'Asha',
            'last_name' => 'Rao',
            'email' => 'asha.rao@example.test',
            'phone' => '98765432101',
            'expected_ctc' => '10 LPA',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $candidate = $this->postJson('/api/v1/recruitment/candidates', [
            'job_id' => $job['id'],
            'first_name' => 'Asha',
            'last_name' => 'Rao',
            'email' => 'asha.rao@example.test',
            'phone' => '9876543210',
            'expected_ctc' => '10 LPA',
        ])->assertCreated()->json('data');

        $this->patchJson("/api/v1/recruitment/candidates/{$candidate['id']}/stage", [
            'stage' => 'Offered',
        ])->assertOk();
        $conversion = $this->postJson("/api/v1/recruitment/candidates/{$candidate['id']}/convert")
            ->assertCreated()
            ->assertJsonMissingPath('data.account_number')
            ->json('data');

        $this->assertDatabaseHas('recruitment_candidates', [
            'id' => $candidate['id'],
            'stage' => 'Hired',
            'employee_id' => $conversion['id'],
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $conversion['id'],
            'email' => 'asha.rao@example.test',
            'designation' => 'Backend Engineer',
            'department' => 'Engineering',
        ]);
        $this->assertDatabaseHas('leave_balances', ['employee_id' => $conversion['id']]);
        $this->assertDatabaseHas('employee_statutory_details', [
            'employee_id' => $conversion['id'],
            'gross_monthly' => 0,
            'account_number' => null,
        ]);
    }

    public function test_missing_active_employee_leave_balances_are_backfilled_without_overwriting_existing_balances(): void
    {
        $employees = DB::table('employees')
            ->whereIn('status', ['active', 'on_notice'])
            ->orderBy('id')
            ->limit(2)
            ->get();

        $this->assertCount(2, $employees);
        $employeeToBackfill = $employees[0];
        $employeeToPreserve = $employees[1];
        DB::table('leave_balances')
            ->where('employee_id', $employeeToPreserve->id)
            ->update([
                'cl' => 3.00,
                'sl' => 4.00,
                'pl' => 5.00,
                'comp_off' => 1.00,
            ]);
        $preservedBalance = DB::table('leave_balances')
            ->where('employee_id', $employeeToPreserve->id)
            ->first();

        $this->assertNotNull($preservedBalance);

        DB::table('leave_balances')
            ->where('employee_id', $employeeToBackfill->id)
            ->delete();

        $migration = require database_path('migrations/2026_11_14_000014_backfill_missing_leave_balances.php');
        $migration->up();

        $backfilledBalance = DB::table('leave_balances')
            ->where('employee_id', $employeeToBackfill->id)
            ->first();

        $this->assertNotNull($backfilledBalance);
        $this->assertSame(12.0, (float) $backfilledBalance->cl);
        $this->assertSame(12.0, (float) $backfilledBalance->sl);
        $this->assertSame(15.0, (float) $backfilledBalance->pl);
        $this->assertSame(0.0, (float) $backfilledBalance->comp_off);
        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $employeeToPreserve->id,
            'cl' => $preservedBalance->cl,
            'sl' => $preservedBalance->sl,
            'pl' => $preservedBalance->pl,
            'comp_off' => $preservedBalance->comp_off,
        ]);
    }

    public function test_project_updates_and_payroll_lock_are_saved_by_the_server(): void
    {
        $employee = DB::table('employees')->first();
        $startDate = now()->subDays(10)->toDateString();
        $pastDueDate = now()->subDay()->toDateString();
        $project = $this->postJson('/api/v1/projects', [
            'name' => 'Remote-backed project',
            'description' => 'Persist each project action.',
            'deliverable' => 'Saved workflow state',
            'assigned_employee_id' => $employee->id,
            'start_date' => $startDate,
            'planned_end_date' => $pastDueDate,
        ])->assertCreated()->json('data');
        $assignmentId = $project['assignment_id'];

        $this->postJson("/api/v1/project-assignments/{$assignmentId}/progress", [
            'progress_percent' => 40,
            'note' => 'First milestone',
            'version' => 1,
        ])->assertOk();
        $extension = $this->postJson("/api/v1/project-assignments/{$assignmentId}/extension-requests", [
            'requested_due_date' => now()->addDays(15)->toDateString(),
            'reason' => 'Dependency delay',
        ])->assertCreated()->json('data.extension_id');
        $this->postJson("/api/v1/project-extension-requests/{$extension}/approve", [
            'decision' => 'rejected',
            'decision_note' => 'Please retain the original date.',
        ])->assertOk();
        $penalty = $this->postJson("/api/v1/project-assignments/{$assignmentId}/penalties", [
            'reason' => 'Recorded for tracking',
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/project-penalties/{$penalty}/waive", [
            'waiver_reason' => 'Manager approved waiver',
        ])->assertOk();

        $this->assertDatabaseHas('project_progress_updates', [
            'assignment_id' => $assignmentId,
            'progress_percent' => 40,
        ]);
        $this->assertDatabaseHas('project_extension_requests', [
            'id' => $extension,
            'status' => 'rejected',
        ]);
        $this->assertDatabaseHas('project_penalties', [
            'id' => $penalty,
            'status' => 'waived',
        ]);

        $payroll = $this->postJson('/api/v1/payroll/runs', [
            'period_year' => 2026,
            'period_month' => 8,
            'scope_key' => 'company',
            'month_name' => 'August 2026',
        ])->assertCreated()->json('data');
        $payslips = DB::table('payslips')->where('payroll_run_id', $payroll['id'])->get();
        foreach ($payslips as $payslip) {
            $this->patchJson("/api/v1/payroll/runs/{$payroll['id']}/payslips/{$payslip->id}/deductions", [
                'professional_tax' => 0,
                'tds' => 0,
                'tds_override_reason' => 'No verified tax table is configured in this isolated test.',
            ])->assertOk();
        }
        $this->postJson("/api/v1/payroll/runs/{$payroll['id']}/verify")->assertOk();
        $this->postJson("/api/v1/payroll/runs/{$payroll['id']}/lock", [
            'approved_by' => 'Untrusted Client Name',
        ])->assertOk();
        $this->assertDatabaseHas('payroll_runs', [
            'id' => $payroll['id'],
            'status' => 'published',
            'approved_by' => User::where('role', 'super_admin')->value('name'),
        ]);
    }
}
