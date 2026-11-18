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
            ->where('id', '<>', $employee->employee_id)
            ->update(['reporting_manager_id' => null]);
        DB::table('employees')
            ->where('id', $employee->employee_id)
            ->update(['reporting_manager_id' => $manager->employee_id]);
        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/employees')->assertOk();
        $this->assertSame(
            [$employee->employee_id],
            array_column($response->json('data'), 'id')
        );
        $managerView = $response->json('data.0');
        $this->assertArrayNotHasKey('email', $managerView);
        $this->assertArrayNotHasKey('phone', $managerView);
        $this->assertArrayNotHasKey('gender', $managerView);
        $this->assertArrayNotHasKey('date_of_birth', $managerView);
        $this->assertArrayNotHasKey('bank_name', $managerView);
        $this->getJson('/api/v1/payroll/runs')->assertForbidden();
        $this->postJson('/api/v1/employees', [])->assertForbidden();
    }

    public function test_attendance_is_scoped_to_team_for_managers_and_self_for_employees(): void
    {
        $manager = User::where('role', 'manager')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        $outsideEmployee = User::where('role', 'recruiter')->firstOrFail();
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $date = '2026-10-10';

        DB::table('employees')->update(['reporting_manager_id' => null]);
        DB::table('employees')->where('id', $employee->employee_id)->update([
            'reporting_manager_id' => $manager->employee_id,
        ]);
        DB::table('attendance_records')->delete();

        foreach ([$manager, $employee, $outsideEmployee, $hrAdmin, $superAdmin] as $user) {
            DB::table('attendance_records')->insert([
                'employee_id' => $user->employee_id,
                'date' => $date,
                'punch_in' => '09:00 AM',
                'punch_out' => '05:00 PM',
                'status' => 'Present',
                'work_hours' => 8,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Sanctum::actingAs($manager);
        $managerAttendance = $this->getJson('/api/v1/attendance')->assertOk()->json('data');
        $managerEmployeeIds = array_values(array_unique(array_map(
            'intval',
            array_column($managerAttendance, 'employee_id')
        )));
        sort($managerEmployeeIds);
        $expectedManagerIds = [(int) $manager->employee_id, (int) $employee->employee_id];
        sort($expectedManagerIds);
        $this->assertSame($expectedManagerIds, $managerEmployeeIds);

        Sanctum::actingAs($employee);
        $this->assertSame(
            [(int) $employee->employee_id],
            array_values(array_unique(array_map(
                'intval',
                array_column($this->getJson('/api/v1/attendance')->assertOk()->json('data'), 'employee_id')
            )))
        );

        foreach ([$hrAdmin, $superAdmin] as $user) {
            Sanctum::actingAs($user);
            $allAttendance = $this->getJson('/api/v1/attendance')->assertOk()->json('data');
            $this->assertEqualsCanonicalizing(
                [
                    (int) $manager->employee_id,
                    (int) $employee->employee_id,
                    (int) $outsideEmployee->employee_id,
                    (int) $hrAdmin->employee_id,
                    (int) $superAdmin->employee_id,
                ],
                array_values(array_unique(array_map(
                    'intval',
                    array_column($allAttendance, 'employee_id')
                )))
            );
        }
    }

    public function test_employee_can_view_own_projects_but_cannot_open_project_management(): void
    {
        $employee = User::where('role', 'employee')->firstOrFail();
        Sanctum::actingAs($employee);

        $this->getJson('/api/v1/projects')->assertForbidden();
        $projects = $this->getJson('/api/v1/projects/mine')->assertOk()->json('data');
        $this->assertNotEmpty($projects);
        $this->assertSame(
            [(int) $employee->employee_id],
            array_values(array_unique(array_map(
                'intval',
                array_column($projects, 'assigned_employee_id')
            )))
        );
    }

    public function test_manager_project_management_list_is_limited_to_their_team(): void
    {
        $manager = User::where('role', 'manager')->firstOrFail();
        $teamEmployeeId = DB::table('employees')
            ->where('reporting_manager_id', $manager->employee_id)
            ->value('id');
        $this->assertNotNull($teamEmployeeId);
        Sanctum::actingAs($manager);

        $projects = $this->getJson('/api/v1/projects')->assertOk()->json('data');
        $projectEmployeeIds = array_map('intval', array_column($projects, 'assigned_employee_id'));

        $this->assertContains((int) $teamEmployeeId, $projectEmployeeIds);
        $this->assertNotContains(
            (int) DB::table('employees')->where('employee_code', 'EMP-005')->value('id'),
            $projectEmployeeIds
        );
    }

    public function test_hr_can_assign_projects_to_active_employees_but_inactive_employees_are_rejected(): void
    {
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        $employees = DB::table('employees')
            ->where('status', 'active')
            ->orderBy('id')
            ->take(2)
            ->get();
        $this->assertCount(2, $employees);

        $inactiveEmployee = $employees[1];
        DB::table('employees')
            ->where('id', $inactiveEmployee->id)
            ->update(['status' => 'inactive']);
        Sanctum::actingAs($hrAdmin);

        $projectData = [
            'name' => 'HR-assigned active employee project',
            'description' => 'Verify active assignee validation.',
            'deliverable' => 'A completed test deliverable.',
            'assigned_employee_id' => $employees[0]->id,
            'start_date' => now()->toDateString(),
            'planned_end_date' => now()->addDays(7)->toDateString(),
        ];
        $this->postJson('/api/v1/projects', $projectData)
            ->assertCreated()
            ->assertJsonPath('data.assigned_employee_id', $employees[0]->id);

        $projectData['assigned_employee_id'] = $inactiveEmployee->id;
        $this->postJson('/api/v1/projects', $projectData)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('assigned_employee_id');
    }

    public function test_manager_project_list_and_extensions_include_team_or_manager_assigned_work_only(): void
    {
        $manager = User::where('role', 'manager')->firstOrFail();
        $teamEmployeeIds = DB::table('employees')
            ->where('reporting_manager_id', $manager->employee_id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $this->assertNotEmpty($teamEmployeeIds);

        $outsideAssignments = DB::table('project_assignments')
            ->whereNotIn('employee_id', $teamEmployeeIds)
            ->where('employee_id', '<>', $manager->employee_id)
            ->orderBy('id')
            ->take(2)
            ->get();
        $this->assertCount(2, $outsideAssignments);

        $managerAssigned = $outsideAssignments[0];
        $otherAssigned = $outsideAssignments[1];
        DB::table('project_assignments')
            ->where('id', $managerAssigned->id)
            ->update(['assigned_by' => $manager->name]);
        $this->assertNotSame($manager->name, $otherAssigned->assigned_by);

        $extensionIds = [];
        foreach ([$managerAssigned, $otherAssigned] as $assignment) {
            $extensionIds[] = DB::table('project_extension_requests')->insertGetId([
                'assignment_id' => $assignment->id,
                'requested_by' => 'Assigned developer',
                'old_due_date' => $assignment->active_due_date,
                'requested_due_date' => now()->addDays(10)->toDateString(),
                'reason' => 'Test visibility scope',
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Sanctum::actingAs($manager);
        $managerProjects = collect($this->getJson('/api/v1/projects')->assertOk()->json('data'));
        $managerProject = $managerProjects->firstWhere('assignment_id', $managerAssigned->id);
        $this->assertNotNull($managerProject);
        $this->assertNull($managerProjects->firstWhere('assignment_id', $otherAssigned->id));
        $this->assertContains(
            $extensionIds[0],
            array_column($managerProject['extensions'], 'id')
        );
        $this->assertNotContains(
            $extensionIds[1],
            $managerProjects->flatMap(fn ($project) => $project['extensions'])->pluck('id')->all()
        );

        foreach (['hr_admin', 'super_admin'] as $role) {
            Sanctum::actingAs(User::where('role', $role)->firstOrFail());
            $allProjects = collect($this->getJson('/api/v1/projects')->assertOk()->json('data'));
            $this->assertNotNull($allProjects->firstWhere('assignment_id', $managerAssigned->id));
            $this->assertNotNull($allProjects->firstWhere('assignment_id', $otherAssigned->id));
        }

        $employee = User::where('employee_id', $managerAssigned->employee_id)->firstOrFail();
        Sanctum::actingAs($employee);
        $this->getJson('/api/v1/projects')->assertForbidden();
        $employeeProjects = $this->getJson('/api/v1/projects/mine')->assertOk()->json('data');
        $this->assertNotEmpty($employeeProjects);
        $this->assertSame(
            [(int) $employee->employee_id],
            array_values(array_unique(array_map(
                'intval',
                array_column($employeeProjects, 'assigned_employee_id')
            )))
        );
    }

    public function test_employee_profiles_include_reporting_manager_name_and_linked_user_role(): void
    {
        $employee = User::where('role', 'employee')->firstOrFail();
        $manager = User::where('role', 'manager')->firstOrFail();
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        DB::table('employees')
            ->where('id', $employee->employee_id)
            ->update(['reporting_manager_id' => $manager->employee_id]);

        Sanctum::actingAs($superAdmin);
        $profiles = $this->getJson('/api/v1/employees')->assertOk()->json('data');
        $employeeProfile = collect($profiles)->firstWhere('id', $employee->employee_id);
        $adminProfile = collect($profiles)->firstWhere('id', $superAdmin->employee_id);

        $this->assertSame($manager->name, $employeeProfile['reporting_manager_name']);
        $this->assertSame('employee', $employeeProfile['role']);
        $this->assertNull($adminProfile['reporting_manager_name']);
        $this->assertSame('super_admin', $adminProfile['role']);
    }

    public function test_hr_can_access_hr_operations_and_projects_but_not_payroll_assets_or_admin_data(): void
    {
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        Sanctum::actingAs($hrAdmin);

        $employees = $this->getJson('/api/v1/employees')->assertOk();
        $firstEmployee = $employees->json('data.0');
        $this->assertArrayHasKey('pan', $firstEmployee);
        $this->assertArrayHasKey('ctc_annual', $firstEmployee);
        $this->assertArrayHasKey('gross_monthly', $firstEmployee);
        $this->assertArrayHasKey('basic_monthly', $firstEmployee);
        $this->assertArrayHasKey('bank_name', $firstEmployee);
        $this->assertArrayHasKey('account_number', $firstEmployee);
        $this->assertArrayHasKey('ifsc_code', $firstEmployee);

        $this->getJson('/api/v1/payroll/runs')->assertForbidden();
        $this->getJson('/api/v1/projects')->assertOk();
        $this->getJson('/api/v1/assets')->assertForbidden();
        $this->getJson('/api/v1/platform/audit-logs')->assertForbidden();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/uploads?module=project_deliverables')->assertForbidden();

        $this->postJson('/api/v1/employees', [])->assertUnprocessable();
    }

    public function test_specialist_roles_receive_only_their_own_workspace_data(): void
    {
        $otherEmployeeId = User::where('role', 'employee')->firstOrFail()->employee_id;

        $assignmentId = DB::table('project_assignments')->orderBy('id')->value('id');

        foreach (['recruiter', 'payroll_admin', 'auditor'] as $role) {
            $user = User::where('role', $role)->firstOrFail();
            $this->assertNotNull($user->employee_id);
            DB::table('project_assignments')->where('id', $assignmentId)->update([
                'employee_id' => $user->employee_id,
            ]);
            Sanctum::actingAs($user);

            $attendance = $this->getJson('/api/v1/attendance')->assertOk()->json('data');
            $leaves = $this->getJson('/api/v1/leaves')->assertOk()->json('data');
            $balances = $this->getJson('/api/v1/leaves/balances')->assertOk()->json('data');
            $this->getJson('/api/v1/projects')->assertForbidden();
            $projects = $this->getJson('/api/v1/projects/mine')->assertOk()->json('data');

            foreach ([$attendance, $leaves] as $records) {
                $employeeIds = array_map('intval', array_column($records, 'employee_id'));
                $this->assertEmpty(array_diff($employeeIds, [(int) $user->employee_id]));
            }
            $projectEmployeeIds = array_map('intval', array_column($projects, 'assigned_employee_id'));
            $this->assertNotEmpty($projects);
            $this->assertEmpty(array_diff($projectEmployeeIds, [(int) $user->employee_id]));
            $balanceEmployeeIds = array_map('intval', array_keys($balances));
            $this->assertEmpty(array_diff($balanceEmployeeIds, [(int) $user->employee_id]));

            $this->postJson('/api/v1/attendance/punch', [
                'employee_id' => $otherEmployeeId,
            ])->assertForbidden();
            $this->postJson('/api/v1/leaves/apply', [
                'employee_id' => $otherEmployeeId,
                'leave_type' => 'Casual Leave',
                'from_date' => '2026-12-01',
                'to_date' => '2026-12-01',
                'days' => 1,
            ])->assertForbidden();
        }
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
        $existingManagerAssignments = DB::table('employees')
            ->orderBy('id')
            ->pluck('reporting_manager_id', 'id');

        $this->artisan('hrms:create-demo-role-accounts', ['--force' => true])
            ->assertExitCode(0);

        foreach ($existingManagerAssignments as $employeeId => $managerId) {
            $this->assertSame(
                $managerId === null ? null : (int) $managerId,
                DB::table('employees')->where('id', $employeeId)->value('reporting_manager_id')
            );
        }

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
        $this->assertDatabaseHas('employees', [
            'employee_code' => 'DEMO-EMP-001',
            'is_demo' => true,
        ]);

        $demoManager = User::where('email', 'demo.manager@pinweb.in')->firstOrFail();
        $demoEmployee = User::where('email', 'demo.employee@pinweb.in')->firstOrFail();
        $this->assertDatabaseHas('employee_statutory_details', [
            'employee_id' => $demoEmployee->employee_id,
            'gross_monthly' => 65000,
            'basic_monthly' => 32500,
            'ctc_annual' => 780000,
            'bank_name' => 'Demo Bank (Test Only)',
            'account_number' => '999999000001',
            'ifsc_code' => 'DEMO0000001',
        ]);
        $demoHr = User::where('email', 'demo.hr@pinweb.in')->firstOrFail();
        $demoAdmin = User::where('email', 'demo.admin@pinweb.in')->firstOrFail();
        $director = User::where('employee_id', DB::table('employees')
            ->where('employee_code', 'EMP-001')
            ->value('id'))->firstOrFail();
        $this->assertDatabaseHas('employees', [
            'id' => $demoEmployee->employee_id,
            'reporting_manager_id' => $demoManager->employee_id,
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $demoManager->employee_id,
            'reporting_manager_id' => $director->employee_id,
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $demoHr->employee_id,
            'reporting_manager_id' => $director->employee_id,
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $demoAdmin->employee_id,
            'reporting_manager_id' => $director->employee_id,
        ]);
        $this->assertDatabaseHas('employees', [
            'employee_code' => 'EMP-002',
            'reporting_manager_id' => $director->employee_id,
        ]);
        $this->assertSame(0, DB::table('leave_requests')
            ->whereIn('employee_id', DB::table('employees')
                ->whereNotIn('id', [
                    $demoEmployee->employee_id,
                    $demoManager->employee_id,
                    $demoHr->employee_id,
                    $demoAdmin->employee_id,
                ])
                ->select('id'))
            ->whereIn('reason', [
                'Demo sample pending leave request',
                'Demo sample approved leave request',
            ])
            ->count());
        $sampleLeaves = DB::table('leave_requests')
            ->where('employee_id', $demoEmployee->employee_id)
            ->whereIn('reason', [
                'Demo sample pending leave request',
                'Demo sample approved leave request',
            ])
            ->get()
            ->keyBy('status');
        $this->assertCount(2, $sampleLeaves);
        $this->assertSame('Manager Approval', $sampleLeaves['pending']->approval_stage);
        $this->assertSame('Approved', $sampleLeaves['approved']->approval_stage);
        $this->assertSame(2, DB::table('leave_request_reviews')
            ->where('leave_request_id', $sampleLeaves['approved']->id)
            ->count());

        $userCount = DB::table('users')->count();
        $sampleLeaveCount = DB::table('leave_requests')
            ->whereIn('reason', [
                'Demo sample pending leave request',
                'Demo sample approved leave request',
            ])
            ->count();
        $passwordHash = $demoEmployee->password;
        DB::table('employee_statutory_details')
            ->where('employee_id', $demoEmployee->employee_id)
            ->update([
                'gross_monthly' => 70000,
                'basic_monthly' => 35000,
                'ctc_annual' => 840000,
                'bank_name' => 'Existing Demo Bank',
                'account_number' => '999999888888',
                'ifsc_code' => 'EXST0000001',
            ]);
        $this->artisan('hrms:create-demo-role-accounts', ['--force' => true])
            ->assertExitCode(0);
        $this->assertSame($userCount, DB::table('users')->count());
        $this->assertSame($sampleLeaveCount, DB::table('leave_requests')
            ->whereIn('reason', [
                'Demo sample pending leave request',
                'Demo sample approved leave request',
            ])
            ->count());
        $this->assertSame($passwordHash, $demoEmployee->fresh()->password);
        $this->assertDatabaseHas('employee_statutory_details', [
            'employee_id' => $demoEmployee->employee_id,
            'gross_monthly' => 70000,
            'basic_monthly' => 35000,
            'ctc_annual' => 840000,
            'bank_name' => 'Existing Demo Bank',
            'account_number' => '999999888888',
            'ifsc_code' => 'EXST0000001',
        ]);
    }

    public function test_install_command_seeds_manager_links_by_employee_code(): void
    {
        $directorId = DB::table('employees')->where('employee_code', 'EMP-001')->value('id');
        $hrEmployeeId = DB::table('employees')->where('employee_code', 'EMP-002')->value('id');
        $teamMemberId = DB::table('employees')->where('employee_code', 'EMP-009')->value('id');
        $otherBranchEmployeeId = DB::table('employees')->where('employee_code', 'EMP-005')->value('id');
        $managerId = DB::table('employees')->where('employee_code', 'EMP-004')->value('id');

        $this->assertDatabaseHas('employees', [
            'id' => $hrEmployeeId,
            'reporting_manager_id' => $directorId,
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $directorId,
            'department_id' => DB::table('departments')
                ->where('name', 'Engineering & Technology')
                ->value('id'),
            'designation_id' => DB::table('designations')
                ->where('title', 'Director of Engineering')
                ->value('id'),
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $teamMemberId,
            'reporting_manager_id' => $managerId,
        ]);
        $this->assertDatabaseHas('employees', [
            'id' => $otherBranchEmployeeId,
            'reporting_manager_id' => null,
        ]);
    }

    public function test_demo_seeder_links_emp002_when_it_is_an_employee_named_ankit_singh(): void
    {
        $ankitId = DB::table('employees')->where('employee_code', 'EMP-002')->value('id');
        DB::table('employees')->where('id', $ankitId)->update([
            'first_name' => 'Ankit',
            'last_name' => 'Singh',
        ]);
        DB::table('users')->where('employee_id', $ankitId)->update(['role' => 'employee']);

        $this->artisan('hrms:create-demo-role-accounts', ['--force' => true])
            ->assertExitCode(0);

        $managerId = DB::table('employees')->where('employee_code', 'DEMO-MGR-001')->value('id');
        $this->assertDatabaseHas('employees', [
            'id' => $ankitId,
            'reporting_manager_id' => $managerId,
        ]);
        $approvedLeave = DB::table('leave_requests')
            ->where('employee_id', $ankitId)
            ->where('reason', 'Demo sample approved leave request')
            ->first();
        $this->assertNotNull($approvedLeave);
        $this->assertSame('approved', $approvedLeave->status);
    }

    public function test_seed_commands_refuse_production_without_force(): void
    {
        config(['app.env' => 'production']);
        $usersBefore = DB::table('users')->count();

        $this->artisan('hrms:create-demo-role-accounts')
            ->assertExitCode(1);
        $this->artisan('hrms:install')
            ->assertExitCode(1);

        $this->assertSame($usersBefore, DB::table('users')->count());
    }

    public function test_database_seeder_refuses_production_without_force(): void
    {
        config(['app.env' => 'production']);
        $this->expectException(\RuntimeException::class);

        (new \Database\Seeders\DatabaseSeeder())->run();
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
