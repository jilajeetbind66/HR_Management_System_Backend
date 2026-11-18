<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeaveApprovalWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
    }

    public function test_employee_leave_routes_through_manager_then_hr_and_records_missing_manager_stage(): void
    {
        $employee = User::where('role', 'employee')->firstOrFail();
        $manager = User::where('role', 'manager')->firstOrFail();
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        $this->assertNotNull($employee->employee_id);
        $this->assertNotNull($manager->employee_id);
        $this->assertNotNull($hrAdmin->employee_id);

        DB::table('employees')->where('id', $employee->employee_id)->update([
            'reporting_manager_id' => $manager->employee_id,
        ]);
        DB::table('leave_balances')->updateOrInsert(
            ['employee_id' => $employee->employee_id],
            ['cl' => 10, 'sl' => 10, 'pl' => 10, 'comp_off' => 0, 'updated_at' => now(), 'created_at' => now()]
        );

        Sanctum::actingAs($employee);
        $leave = $this->applyLeave()->assertCreated()->json('data');
        $this->assertSame('Manager Approval', $leave['approval_stage']);
        $this->assertSame('manager', $leave['next_approver_role']);
        $this->postJson("/api/v1/leaves/{$leave['id']}/review", ['status' => 'approved'])->assertForbidden();

        Sanctum::actingAs($hrAdmin);
        $this->postJson("/api/v1/leaves/{$leave['id']}/review", ['status' => 'approved'])->assertForbidden();

        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/leaves/{$leave['id']}/review", ['status' => 'approved'])
            ->assertOk()
            ->assertJsonPath('data.approval_stage', 'HR Approval');
        $this->assertDatabaseHas('leave_requests', [
            'id' => $leave['id'],
            'status' => 'pending',
            'approval_stage' => 'HR Approval',
            'next_approver_role' => 'hr',
            'reviewed_by' => $manager->name,
        ]);

        Sanctum::actingAs($hrAdmin);
        $this->postJson("/api/v1/leaves/{$leave['id']}/review", ['status' => 'approved'])->assertOk();
        $this->assertDatabaseHas('leave_requests', [
            'id' => $leave['id'],
            'status' => 'approved',
            'approval_stage' => 'Approved',
            'next_approver_role' => null,
        ]);
        $this->assertEquals(9, DB::table('leave_balances')
            ->where('employee_id', $employee->employee_id)
            ->value('cl'));

        DB::table('employees')->where('id', $employee->employee_id)->update([
            'reporting_manager_id' => null,
        ]);
        Sanctum::actingAs($employee);
        $managerlessLeave = $this->applyLeave(90)->assertCreated()->json('data');
        $this->assertSame('No manager assigned', $managerlessLeave['approval_stage']);
        $this->assertSame('hr', $managerlessLeave['next_approver_role']);
        Sanctum::actingAs($hrAdmin);
        $this->postJson("/api/v1/leaves/{$managerlessLeave['id']}/review", ['status' => 'approved'])
            ->assertOk();
    }

    public function test_manager_hr_and_super_admin_leave_requests_follow_their_approver_routes(): void
    {
        $manager = User::where('role', 'manager')->firstOrFail();
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        $superAdmin = User::where('role', 'super_admin')->firstOrFail();
        $this->assertNotNull($manager->employee_id);
        $this->assertNotNull($hrAdmin->employee_id);
        $this->assertNotNull($superAdmin->employee_id);

        foreach ([$manager, $hrAdmin, $superAdmin] as $applicant) {
            DB::table('leave_balances')->updateOrInsert(
                ['employee_id' => $applicant->employee_id],
                ['cl' => 10, 'sl' => 10, 'pl' => 10, 'comp_off' => 0, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        Sanctum::actingAs($manager);
        $managerLeave = $this->applyLeave()->assertCreated()->json('data');
        $this->assertSame('HR Approval', $managerLeave['approval_stage']);
        $this->assertSame('hr', $managerLeave['next_approver_role']);

        Sanctum::actingAs($hrAdmin);
        $hrLeave = $this->applyLeave()->assertCreated()->json('data');
        $this->assertSame('Super Admin Approval', $hrLeave['approval_stage']);
        $this->assertSame('super_admin', $hrLeave['next_approver_role']);
        $this->postJson("/api/v1/leaves/{$hrLeave['id']}/review", ['status' => 'approved'])
            ->assertForbidden();

        Sanctum::actingAs($superAdmin);
        $this->postJson("/api/v1/leaves/{$hrLeave['id']}/review", ['status' => 'approved'])->assertOk();
        $superAdminLeave = $this->applyLeave()->assertCreated()->json('data');
        $this->assertSame('Super Admin / HR Head Approval', $superAdminLeave['approval_stage']);
        $this->assertSame('super_admin_or_hr_head', $superAdminLeave['next_approver_role']);
        $this->postJson("/api/v1/leaves/{$superAdminLeave['id']}/review", ['status' => 'approved'])
            ->assertForbidden();

        $alternateApprover = User::factory()->create();
        $alternateApprover->role = 'super_admin';
        $alternateApprover->save();
        Sanctum::actingAs($alternateApprover);
        $this->postJson("/api/v1/leaves/{$superAdminLeave['id']}/review", ['status' => 'approved'])
            ->assertOk();
    }

    public function test_only_the_applicant_can_cancel_their_own_pending_leave(): void
    {
        $employee = User::where('role', 'employee')->firstOrFail();
        $otherEmployee = User::where('role', 'manager')->firstOrFail();
        DB::table('leave_balances')->updateOrInsert(
            ['employee_id' => $employee->employee_id],
            ['cl' => 5, 'sl' => 5, 'pl' => 5, 'comp_off' => 0, 'updated_at' => now(), 'created_at' => now()]
        );
        Sanctum::actingAs($employee);
        $leave = $this->applyLeave()->assertCreated()->json('data');

        Sanctum::actingAs($otherEmployee);
        $this->postJson("/api/v1/leaves/{$leave['id']}/cancel")->assertForbidden();

        Sanctum::actingAs($employee);
        $this->postJson("/api/v1/leaves/{$leave['id']}/cancel")->assertOk();
        $this->assertEquals(5, DB::table('leave_balances')
            ->where('employee_id', $employee->employee_id)
            ->value('cl'));
        $this->assertDatabaseHas('leave_requests', [
            'id' => $leave['id'],
            'status' => 'cancelled',
            'approval_stage' => 'Cancelled',
        ]);
        $this->postJson("/api/v1/leaves/{$leave['id']}/cancel")->assertStatus(409);
    }

    public function test_rejection_requires_remarks_and_review_history_is_returned_with_leave_details(): void
    {
        $employee = User::where('role', 'employee')->firstOrFail();
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        DB::table('employees')->where('id', $employee->employee_id)->update([
            'reporting_manager_id' => null,
        ]);
        Sanctum::actingAs($employee);
        $leave = $this->applyLeave()->assertCreated()->json('data');
        $this->assertSame('No manager assigned', $leave['approval_stage']);
        DB::table('leave_balances')->where('employee_id', $employee->employee_id)->update(['cl' => 10]);

        Sanctum::actingAs($hrAdmin);
        $this->postJson("/api/v1/leaves/{$leave['id']}/review", ['status' => 'rejected'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('remarks');
        $this->assertDatabaseHas('leave_requests', [
            'id' => $leave['id'],
            'status' => 'pending',
        ]);

        $remarks = "Please apply under Sick Leave.\nSupporting details are missing.";
        $this->postJson("/api/v1/leaves/{$leave['id']}/review", [
            'status' => 'rejected',
            'remarks' => $remarks,
        ])->assertOk();

        Sanctum::actingAs($employee);
        $savedLeave = collect($this->getJson('/api/v1/leaves')->assertOk()->json('data'))
            ->firstWhere('id', $leave['id']);
        $this->assertSame('rejected', $savedLeave['status']);
        $this->assertSame('Rejected', $savedLeave['approval_stage']);
        $this->assertSame($remarks, $savedLeave['review_remarks']);
        $this->assertNotEmpty($savedLeave['created_at']);
        $this->assertSame(1, count($savedLeave['reviews']));
        $this->assertSame('No manager assigned', $savedLeave['reviews'][0]['stage']);
        $this->assertSame($hrAdmin->name, $savedLeave['reviews'][0]['reviewer_name']);
        $this->assertSame($remarks, $savedLeave['reviews'][0]['remarks']);
        $this->assertEquals(10, DB::table('leave_balances')
            ->where('employee_id', $employee->employee_id)
            ->value('cl'));
    }

    public function test_leave_lists_and_balances_are_scoped_and_query_filters_respect_that_scope(): void
    {
        $manager = User::where('role', 'manager')->firstOrFail();
        $teamEmployee = User::where('role', 'employee')->firstOrFail();
        $outsideEmployee = User::where('role', 'recruiter')->firstOrFail();
        $this->assertNotNull($manager->employee_id);
        $this->assertNotNull($teamEmployee->employee_id);
        $this->assertNotNull($outsideEmployee->employee_id);

        DB::table('employees')->update(['reporting_manager_id' => null]);
        DB::table('employees')->where('id', $teamEmployee->employee_id)->update([
            'reporting_manager_id' => $manager->employee_id,
        ]);
        $date = now()->addYears(5)->toDateString();
        $teamPendingId = $this->createLeaveRequest((int) $teamEmployee->employee_id, $date, 'pending');
        $teamApprovedId = $this->createLeaveRequest((int) $teamEmployee->employee_id, $date, 'approved');
        $managerLeaveId = $this->createLeaveRequest((int) $manager->employee_id, $date, 'pending');
        $outsideLeaveId = $this->createLeaveRequest((int) $outsideEmployee->employee_id, $date, 'pending');
        foreach ([$manager->employee_id, $teamEmployee->employee_id, $outsideEmployee->employee_id] as $employeeId) {
            DB::table('leave_balances')->updateOrInsert(
                ['employee_id' => $employeeId],
                ['cl' => 5, 'sl' => 5, 'pl' => 5, 'comp_off' => 0, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        Sanctum::actingAs($manager);
        $teamLeaves = collect($this->getJson('/api/v1/leaves')->assertOk()->json('data'));
        $teamIds = $teamLeaves->pluck('id')->all();
        $this->assertContains($teamPendingId, $teamIds);
        $this->assertContains($teamApprovedId, $teamIds);
        $this->assertNotContains($managerLeaveId, $teamIds);
        $this->assertNotContains($outsideLeaveId, $teamIds);
        $this->assertSame(
            [(int) $manager->employee_id],
            array_map('intval', array_column($this->getJson('/api/v1/leaves?scope=mine')->assertOk()->json('data'), 'employee_id'))
        );
        $teamBalanceIds = array_map('intval', array_keys($this->getJson('/api/v1/leaves/balances')->assertOk()->json('data')));
        $this->assertSame([(int) $teamEmployee->employee_id], $teamBalanceIds);
        $ownBalanceIds = array_map('intval', array_keys($this->getJson('/api/v1/leaves/balances?scope=mine')->assertOk()->json('data')));
        $this->assertSame([(int) $manager->employee_id], $ownBalanceIds);

        $filtered = $this->getJson(
            "/api/v1/leaves?status=pending&employee_id={$teamEmployee->employee_id}&from_date={$date}&to_date={$date}"
        )->assertOk()->json('data');
        $this->assertSame([$teamPendingId], array_column($filtered, 'id'));

        Sanctum::actingAs($teamEmployee);
        $employeeIds = array_map('intval', array_column($this->getJson('/api/v1/leaves')->assertOk()->json('data'), 'employee_id'));
        $this->assertSame([(int) $teamEmployee->employee_id], array_values(array_unique($employeeIds)));

        Sanctum::actingAs(User::where('role', 'hr_admin')->firstOrFail());
        $allIds = collect($this->getJson('/api/v1/leaves')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($managerLeaveId, $allIds);
        $this->assertContains($outsideLeaveId, $allIds);
    }

    public function test_leave_balances_are_prorated_and_track_used_and_pending_days(): void
    {
        Carbon::setTestNow('2026-11-17 12:00:00');
        try {
            $employee = User::where('role', 'employee')->firstOrFail();
            $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
            $joiningDate = now()->subDays(90)->toDateString();
            DB::table('employees')->where('id', $employee->employee_id)->update([
                'date_of_joining' => $joiningDate,
                'reporting_manager_id' => null,
            ]);
            DB::table('leave_balances')->updateOrInsert(
                ['employee_id' => $employee->employee_id],
                ['cl' => 12, 'sl' => 12, 'pl' => 15, 'comp_off' => 0, 'updated_at' => now(), 'created_at' => now()]
            );
            $this->insertLeave((int) $employee->employee_id, 'Casual Leave (CL)', '2026-10-01', 1, 'approved');
            $this->insertLeave((int) $employee->employee_id, 'Casual Leave (CL)', '2026-12-01', 0.5, 'pending');

            Sanctum::actingAs($employee);
            $balance = $this->getJson('/api/v1/leaves/balances')
                ->assertOk()
                ->json("data.{$employee->employee_id}");
            $this->assertLessThan(12, $balance['cl_accrued']);
            $this->assertGreaterThan(0, $balance['cl_accrued']);
            $this->assertSame(1.0, (float) $balance['cl_used']);
            $this->assertSame(0.5, (float) $balance['cl_pending']);
            $this->assertSame(
                round($balance['cl_accrued'] - 1, 2),
                (float) $balance['cl_available']
            );

            $overBudgetRequest = $this->insertLeave(
                (int) $employee->employee_id,
                'Casual Leave (CL)',
                '2026-12-10',
                2,
                'pending'
            );
            Sanctum::actingAs($hrAdmin);
            $this->postJson("/api/v1/leaves/{$overBudgetRequest}/review", ['status' => 'approved'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('status');
            $this->assertDatabaseHas('leave_requests', [
                'id' => $overBudgetRequest,
                'status' => 'pending',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_leave_days_apply_weekend_holiday_half_day_overlap_and_punch_rules(): void
    {
        Carbon::setTestNow('2026-11-17 12:00:00');
        try {
            $employee = User::where('role', 'employee')->firstOrFail();
            DB::table('employees')->where('id', $employee->employee_id)->update([
                'date_of_joining' => '2020-01-01',
            ]);
            DB::table('leave_balances')->updateOrInsert(
                ['employee_id' => $employee->employee_id],
                ['cl' => 12, 'sl' => 12, 'pl' => 15, 'comp_off' => 0, 'updated_at' => now(), 'created_at' => now()]
            );
            DB::table('holidays')->whereBetween('date', ['2026-11-20', '2026-11-26'])->delete();
            DB::table('attendance_records')->where('employee_id', $employee->employee_id)
                ->whereBetween('date', ['2026-11-20', '2026-11-26'])
                ->delete();
            DB::table('attendance_records')->insert([
                'employee_id' => $employee->employee_id,
                'date' => '2026-11-20',
                'punch_in' => '09:00:00',
                'status' => 'Present',
                'work_hours' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Sanctum::actingAs($employee);
            $sandwichLeave = $this->postJson('/api/v1/leaves/apply', [
                'leave_type' => 'Casual Leave (CL)',
                'from_date' => '2026-11-20',
                'to_date' => '2026-11-23',
                'days' => 1,
                'reason' => 'Weekend sandwich calculation.',
            ])->assertCreated();
            $this->assertSame(4.0, (float) $sandwichLeave->json('data.days'));
            $this->assertContains('Attendance punches already exist on: 2026-11-20.', $sandwichLeave->json('warnings'));

            $this->postJson('/api/v1/leaves/apply', [
                'leave_type' => 'Casual Leave (CL)',
                'from_date' => '2026-11-20',
                'to_date' => '2026-11-20',
                'reason' => 'Overlapping request.',
            ])->assertUnprocessable()->assertJsonValidationErrors('from_date');

            $halfDay = $this->postJson('/api/v1/leaves/apply', [
                'leave_type' => 'Casual Leave (CL)',
                'from_date' => '2026-11-24',
                'to_date' => '2026-11-24',
                'is_half_day' => true,
                'half_day_period' => 'second_half',
                'reason' => 'Half-day request.',
            ])->assertCreated();
            $this->assertSame(0.5, (float) $halfDay->json('data.days'));
            $this->assertTrue((bool) $halfDay->json('data.is_half_day'));
            $this->assertSame('second_half', $halfDay->json('data.half_day_period'));

            $this->postJson('/api/v1/leaves/apply', [
                'leave_type' => 'Casual Leave (CL)',
                'from_date' => '2026-11-21',
                'to_date' => '2026-11-22',
                'reason' => 'Weekend only.',
            ])->assertUnprocessable()->assertJsonValidationErrors('from_date');
            $this->postJson('/api/v1/leaves/apply', [
                'leave_type' => 'Casual Leave (CL)',
                'from_date' => '2026-11-21',
                'to_date' => '2026-11-21',
                'is_half_day' => true,
                'half_day_period' => 'first_half',
                'reason' => 'Weekend half day.',
            ])->assertUnprocessable()->assertJsonValidationErrors('from_date');

            DB::table('holidays')->insert([
                'name' => 'Branch Holiday',
                'date' => '2026-11-25',
                'type' => 'Gazetted',
                'branch_ids' => json_encode([(int) DB::table('employees')->where('id', $employee->employee_id)->value('branch_id')]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->postJson('/api/v1/leaves/apply', [
                'leave_type' => 'Casual Leave (CL)',
                'from_date' => '2026-11-25',
                'to_date' => '2026-11-25',
                'reason' => 'Holiday date.',
            ])->assertUnprocessable()->assertJsonValidationErrors('from_date');
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_leave_application_returns_non_blocking_exclusion_and_type_reason_warnings(): void
    {
        Carbon::setTestNow('2026-11-17 12:00:00');
        try {
            $employee = User::where('role', 'employee')->firstOrFail();
            DB::table('employees')->where('id', $employee->employee_id)->update([
                'date_of_joining' => '2020-01-01',
            ]);
            DB::table('holidays')->whereBetween('date', ['2026-11-20', '2026-11-22'])->delete();
            Sanctum::actingAs($employee);

            $response = $this->postJson('/api/v1/leaves/apply', [
                'leave_type' => 'Casual Leave (CL)',
                'from_date' => '2026-11-20',
                'to_date' => '2026-11-22',
                'reason' => 'Fever and body pain.',
            ])->assertCreated();

            $this->assertSame(1.0, (float) $response->json('data.days'));
            $this->assertContains(
                '2 days, Saturday and Sunday excluded by the weekend/holiday policy.',
                $response->json('warnings')
            );
            $this->assertContains(
                'Your reason mentions a medical concern. Consider whether Sick Leave is more appropriate.',
                $response->json('warnings')
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_final_approval_marks_attendance_notifies_applicant_and_audits_the_decision(): void
    {
        Carbon::setTestNow('2026-11-17 12:00:00');
        try {
            $employee = User::where('role', 'employee')->firstOrFail();
            $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
            DB::table('employees')->where('id', $employee->employee_id)->update([
                'date_of_joining' => '2020-01-01',
                'reporting_manager_id' => null,
            ]);
            DB::table('leave_balances')->updateOrInsert(
                ['employee_id' => $employee->employee_id],
                ['cl' => 0, 'sl' => 0, 'pl' => 0, 'comp_off' => 0, 'updated_at' => now(), 'created_at' => now()]
            );
            DB::table('attendance_records')->where('employee_id', $employee->employee_id)
                ->whereBetween('date', ['2026-11-18', '2026-11-19'])
                ->delete();
            DB::table('attendance_records')->insert([
                'employee_id' => $employee->employee_id,
                'date' => '2026-11-19',
                'punch_in' => '09:00 AM',
                'status' => 'Present',
                'work_hours' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Sanctum::actingAs($employee);
            $leave = $this->postJson('/api/v1/leaves/apply', [
                'leave_type' => 'Leave Without Pay (LOP)',
                'from_date' => '2026-11-18',
                'to_date' => '2026-11-19',
                'reason' => 'Unpaid leave request.',
            ])->assertCreated()->json('data');
            $this->assertSame(2.0, (float) $leave['days']);

            Sanctum::actingAs($hrAdmin);
            $this->postJson("/api/v1/leaves/{$leave['id']}/review", ['status' => 'approved'])
                ->assertOk();

            $this->assertDatabaseHas('attendance_records', [
                'employee_id' => $employee->employee_id,
                'date' => '2026-11-18',
                'status' => 'On Leave',
            ]);
            $this->assertDatabaseHas('attendance_records', [
                'employee_id' => $employee->employee_id,
                'date' => '2026-11-19',
                'status' => 'Present',
                'punch_in' => '09:00 AM',
            ]);
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'LEAVE_REVIEWED',
                'module' => 'Leaves',
                'description' => "Marked leave request #{$leave['id']} approved",
            ]);
            $this->assertDatabaseHas('audit_logs', [
                'action' => 'LEAVE_ATTENDANCE_MARKED',
                'module' => 'Attendance',
            ]);
            $this->assertDatabaseHas('user_notifications', [
                'user_id' => $employee->id,
                'event_key' => 'leave.reviewed',
                'title' => 'Leave request updated',
            ]);

            Sanctum::actingAs($employee);
            $notification = $this->getJson('/api/v1/notifications')
                ->assertOk()
                ->assertJsonPath('unread_count', 1)
                ->json('data.0');
            $this->assertStringContainsString('was approved', $notification['message']);
            $this->postJson("/api/v1/notifications/{$notification['id']}/read")
                ->assertOk()
                ->assertJsonPath('data.read_at', fn ($value) => !empty($value));
            $this->getJson('/api/v1/notifications')->assertJsonPath('unread_count', 0);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_sick_leave_over_two_days_requires_and_links_a_private_certificate(): void
    {
        Carbon::setTestNow('2026-11-17 12:00:00');
        try {
            $employee = User::where('role', 'employee')->firstOrFail();
            DB::table('employees')->where('id', $employee->employee_id)->update([
                'date_of_joining' => '2020-01-01',
                'reporting_manager_id' => null,
            ]);
            DB::table('leave_balances')->updateOrInsert(
                ['employee_id' => $employee->employee_id],
                ['cl' => 12, 'sl' => 12, 'pl' => 15, 'comp_off' => 0, 'updated_at' => now(), 'created_at' => now()]
            );
            DB::table('holidays')->whereBetween('date', ['2026-11-20', '2026-11-23'])->delete();
            Sanctum::actingAs($employee);

            $payload = [
                'leave_type' => 'Sick Leave (SL)',
                'from_date' => '2026-11-20',
                'to_date' => '2026-11-23',
                'reason' => 'Sick leave certificate test.',
            ];
            $this->postJson('/api/v1/leaves/apply', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('certificate_upload_id');

            $certificateId = DB::table('hrms_uploads')->insertGetId([
                'module' => 'leave_certificates',
                'employee_id' => $employee->employee_id,
                'record_id' => null,
                'uploaded_by_user_id' => $employee->id,
                'document_type' => 'Sick Leave Medical Certificate',
                'original_filename' => 'medical-certificate.pdf',
                'cloudinary_public_id' => 'leave-cert-' . uniqid(),
                'cloudinary_resource_type' => 'raw',
                'cloudinary_delivery_type' => 'authenticated',
                'cloudinary_format' => 'pdf',
                'mime_type' => 'application/pdf',
                'file_size' => 1024,
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $response = $this->postJson('/api/v1/leaves/apply', [
                ...$payload,
                'certificate_upload_id' => $certificateId,
            ])->assertCreated();

            $leaveId = $response->json('data.id');
            $this->assertSame(4.0, (float) $response->json('data.days'));
            $this->assertSame($certificateId, $response->json('data.certificate_upload.id'));
            $this->assertDatabaseHas('hrms_uploads', [
                'id' => $certificateId,
                'record_id' => $leaveId,
            ]);

            $uploads = $this->getJson("/api/v1/uploads?module=leave_certificates&record_id={$leaveId}")
                ->assertOk()
                ->json('data');
            $this->assertSame([$certificateId], array_column($uploads, 'id'));
            Sanctum::actingAs(User::where('role', 'manager')->firstOrFail());
            $this->getJson("/api/v1/uploads/{$certificateId}/download")->assertForbidden();

            Sanctum::actingAs($employee);
            $this->postJson('/api/v1/uploads', [
                'module' => 'leave_certificates',
                'employee_id' => $employee->employee_id,
                'file' => UploadedFile::fake()->create('invalid.txt', 1, 'text/plain'),
            ])->assertUnprocessable()
                ->assertJsonValidationErrors('file');
        } finally {
            Carbon::setTestNow();
        }
    }

    private function applyLeave(int $offsetDays = 60)
    {
        $date = now()->addDays($offsetDays);
        while ($date->isWeekend()) {
            $date->addDay();
        }

        return $this->postJson('/api/v1/leaves/apply', [
            'leave_type' => 'Casual Leave (CL)',
            'from_date' => $date->toDateString(),
            'to_date' => $date->toDateString(),
            'days' => 1,
            'reason' => 'Approval routing test.',
        ]);
    }

    private function createLeaveRequest(int $employeeId, string $date, string $status): int
    {
        return DB::table('leave_requests')->insertGetId([
            'employee_id' => $employeeId,
            'leave_type' => 'Casual Leave (CL)',
            'from_date' => $date,
            'to_date' => $date,
            'days' => 1,
            'reason' => 'Leave visibility test.',
            'status' => $status,
            'approval_stage' => $status === 'pending' ? 'HR Approval' : 'Approved',
            'next_approver_role' => $status === 'pending' ? 'hr' : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLeave(int $employeeId, string $type, string $date, float $days, string $status): int
    {
        return DB::table('leave_requests')->insertGetId([
            'employee_id' => $employeeId,
            'leave_type' => $type,
            'from_date' => $date,
            'to_date' => $date,
            'days' => $days,
            'reason' => 'Balance calculation test.',
            'status' => $status,
            'approval_stage' => $status === 'pending' ? 'No manager assigned' : 'Approved',
            'next_approver_role' => $status === 'pending' ? 'hr' : null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
