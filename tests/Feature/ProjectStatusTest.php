<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\ProjectAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

class ProjectStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
    }

    public function test_project_status_and_overdue_filter_follow_the_assignment_state_and_active_deadline(): void
    {
        $pastDue = now()->subDays(2)->toDateString();
        $overdueProject = $this->createProject('Past due work', $pastDue);
        $reviewProject = $this->createProject('Submitted work', $pastDue);
        DB::table('project_assignments')
            ->where('id', $reviewProject['assignment_id'])
            ->update(['status' => 'under_review']);

        $projects = collect($this->getJson('/api/v1/projects')->assertOk()->json('data'));
        $this->assertSame('overdue', $projects->firstWhere('id', $overdueProject['id'])['status']);
        $this->assertGreaterThan(
            0,
            $projects->firstWhere('id', $overdueProject['id'])['overdue_days']
        );
        $this->assertSame('in_progress', $projects->firstWhere('id', $reviewProject['id'])['project_status']);
        $this->assertSame('under_review', $projects->firstWhere('id', $reviewProject['id'])['status']);
        $this->assertTrue($projects->firstWhere('id', $reviewProject['id'])['is_overdue']);
        $this->assertGreaterThan(0, $projects->firstWhere('id', $reviewProject['id'])['overdue_days']);

        $overdueResults = collect($this->getJson('/api/v1/projects?status=overdue')
            ->assertOk()
            ->json('data'));
        $overdueIds = $overdueResults->pluck('id')->all();
        $this->assertContains($overdueProject['id'], $overdueIds);
        $this->assertContains($reviewProject['id'], $overdueIds);
        $inProgressResults = $this->getJson('/api/v1/projects?status=in_progress')
            ->assertOk()
            ->json('data');
        $this->assertNotContains($overdueProject['id'], collect($inProgressResults)->pluck('id')->all());
    }

    public function test_overdue_calculation_excludes_completed_projects_and_includes_other_past_due_states(): void
    {
        Carbon::setTestNow('2026-11-17 12:00:00');
        try {
            $pastDue = '2026-11-16';
            $overdueProject = $this->createProject('Past due in progress', $pastDue);
            $reviewProject = $this->createProject('Past due under review', $pastDue);
            $completedProject = $this->createProject('Past due completed', $pastDue);
            DB::table('project_assignments')->where('id', $reviewProject['assignment_id'])
                ->update(['status' => 'under_review']);
            DB::table('project_assignments')->where('id', $completedProject['assignment_id'])
                ->update(['status' => 'completed']);

            $projects = collect($this->getJson('/api/v1/projects')->assertOk()->json('data'));
            $this->assertTrue($projects->firstWhere('id', $overdueProject['id'])['is_overdue']);
            $this->assertSame('overdue', $projects->firstWhere('id', $overdueProject['id'])['status']);
            $this->assertTrue($projects->firstWhere('id', $reviewProject['id'])['is_overdue']);
            $this->assertSame('under_review', $projects->firstWhere('id', $reviewProject['id'])['status']);
            $this->assertFalse($projects->firstWhere('id', $completedProject['id'])['is_overdue']);
            $this->assertSame(0, $projects->firstWhere('id', $completedProject['id'])['overdue_days']);

            $overdueIds = collect($this->getJson('/api/v1/projects?status=overdue')->assertOk()->json('data'))
                ->pluck('id')
                ->all();
            $this->assertContains($overdueProject['id'], $overdueIds);
            $this->assertContains($reviewProject['id'], $overdueIds);
            $this->assertNotContains($completedProject['id'], $overdueIds);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_high_priority_is_preserved_in_project_creation_and_listing(): void
    {
        $employeeId = DB::table('employees')->where('status', 'active')->value('id');
        $response = $this->postJson('/api/v1/projects', [
            'name' => 'High priority mapping test',
            'description' => 'Verify the selected priority is preserved.',
            'deliverable' => 'A completed test deliverable.',
            'priority' => 'High',
            'assigned_employee_id' => $employeeId,
            'start_date' => now()->toDateString(),
            'planned_end_date' => now()->addDays(7)->toDateString(),
        ])->assertCreated();

        $projectId = $response->json('data.id');
        $this->assertSame('High', $response->json('data.priority'));
        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'priority' => 'High',
        ]);
        $listedProject = collect($this->getJson('/api/v1/projects')->assertOk()->json('data'))
            ->firstWhere('id', $projectId);
        $this->assertNotNull($listedProject);
        $this->assertSame('High', $listedProject['priority']);
    }

    public function test_assigner_name_and_role_are_returned_even_when_admin_assigns_a_project_to_themself(): void
    {
        $admin = User::where('role', 'super_admin')->firstOrFail();
        $this->assertNotNull($admin->employee_id);

        $response = $this->postJson('/api/v1/projects', [
            'name' => 'Admin self-assignment attribution',
            'assigned_employee_id' => $admin->employee_id,
            'start_date' => now()->toDateString(),
            'planned_end_date' => now()->addDays(7)->toDateString(),
        ])->assertCreated();

        $this->assertSame($admin->name, $response->json('data.assigned_by'));
        $this->assertSame($admin->role, $response->json('data.assigned_by_role'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'PROJECT_ASSIGNED',
            'module' => 'Projects',
            'actor' => $admin->name,
        ]);

        $listedProject = collect($this->getJson('/api/v1/projects')->assertOk()->json('data'))
            ->firstWhere('id', $response->json('data.id'));
        $this->assertSame($admin->name, $listedProject['assigned_by']);
        $this->assertSame($admin->role, $listedProject['assigned_by_role']);
    }

    public function test_project_type_is_saved_and_rejects_values_outside_the_supported_categories(): void
    {
        $employeeId = DB::table('employees')->where('status', 'active')->value('id');
        $response = $this->postJson('/api/v1/projects', [
            'name' => 'Bug fix category test',
            'description' => 'Verify project type storage.',
            'deliverable' => 'A completed test deliverable.',
            'project_type' => 'Bug Fix',
            'assigned_employee_id' => $employeeId,
            'start_date' => now()->toDateString(),
            'planned_end_date' => now()->addDays(7)->toDateString(),
        ])->assertCreated();

        $projectId = $response->json('data.id');
        $this->assertSame('Bug Fix', $response->json('data.project_type'));
        $this->assertDatabaseHas('projects', [
            'id' => $projectId,
            'project_type' => 'Bug Fix',
        ]);

        $this->postJson('/api/v1/projects', [
            'name' => 'Invalid project category test',
            'assigned_employee_id' => $employeeId,
            'start_date' => now()->toDateString(),
            'planned_end_date' => now()->addDays(7)->toDateString(),
            'project_type' => 'Other',
        ])->assertUnprocessable()->assertJsonValidationErrors('project_type');
    }

    public function test_baseline_end_date_is_required_and_cannot_precede_start_date(): void
    {
        $employeeId = DB::table('employees')->where('status', 'active')->value('id');
        $projectData = [
        'name' => 'Baseline date validation test',
        'assigned_employee_id' => $employeeId,
        'start_date' => '2026-11-16',
        ];

        $this->postJson('/api/v1/projects', $projectData)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('planned_end_date');

        $projectData['planned_end_date'] = '2026-11-15';
        $this->postJson('/api/v1/projects', $projectData)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('planned_end_date');

        $projectData['planned_end_date'] = '2026-11-16';
        $this->postJson('/api/v1/projects', $projectData)->assertCreated();
    }

    public function test_project_actions_are_limited_to_the_assignee_and_eligible_reviewers(): void
    {
        $assignedEmployee = User::where('role', 'employee')->firstOrFail();
        $otherEmployee = User::where('role', 'employee')
            ->where('id', '<>', $assignedEmployee->id)
            ->firstOrFail();
        $this->assertNotNull($assignedEmployee->employee_id);
        $this->assertNotNull($otherEmployee->employee_id);

        $manager = User::where('role', 'manager')->firstOrFail();
        DB::table('employees')
            ->where('id', $assignedEmployee->employee_id)
            ->update(['reporting_manager_id' => $manager->employee_id]);

        $project = $this->createProject(
            'Project action authorization test',
            now()->addDays(30)->toDateString(),
            (int) $assignedEmployee->employee_id
        );
        $assignmentId = $project['assignment_id'];
        $this->assertSame(
            (int) $manager->employee_id,
            (int) $project['assigned_employee_reporting_manager_id']
        );

        Sanctum::actingAs($otherEmployee);
        $this->postJson("/api/v1/project-assignments/{$assignmentId}/progress", [
            'progress_percent' => 25,
            'note' => 'Unauthorized update',
        ])->assertForbidden();

        Sanctum::actingAs($assignedEmployee);
        $this->postJson("/api/v1/project-assignments/{$assignmentId}/progress", [
            'progress_percent' => 25,
            'note' => 'Assigned developer update',
        ])->assertOk();
        $extensionId = $this->postJson("/api/v1/project-assignments/{$assignmentId}/extension-requests", [
            'requested_due_date' => now()->addDays(45)->toDateString(),
            'reason' => 'Testing assigned developer authorization',
        ])->assertCreated()->json('data.extension_id');
        $this->postJson("/api/v1/project-extension-requests/{$extensionId}/approve", [
            'decision' => 'rejected',
        ])->assertForbidden();

        $otherManager = User::factory()->create();
        $otherManager->role = 'manager';
        $otherManager->employee_id = null;
        $otherManager->save();
        Sanctum::actingAs($otherManager);
        $this->postJson("/api/v1/project-extension-requests/{$extensionId}/approve", [
            'decision' => 'rejected',
        ])->assertForbidden();

        Sanctum::actingAs(User::where('role', 'hr_admin')->firstOrFail());
        $this->postJson("/api/v1/project-extension-requests/{$extensionId}/approve", [
            'decision' => 'approved',
        ])->assertOk();

        Sanctum::actingAs($assignedEmployee);
        $this->postJson("/api/v1/project-assignments/{$assignmentId}/submit", [
            'submission_note' => 'Deliverable submitted for review',
        ])->assertOk();

        Sanctum::actingAs($otherManager);
        $this->postJson("/api/v1/project-assignments/{$assignmentId}/accept", [
            'decision' => 'accept',
        ])->assertForbidden();

        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/project-assignments/{$assignmentId}/accept", [
            'decision' => 'accept',
            'remarks' => 'Reviewed by the reporting manager',
        ])->assertOk();
    }

    public function test_completion_requires_submission_then_records_review_decision_and_notes(): void
    {
        $assignedEmployee = User::where('role', 'employee')->firstOrFail();
        $project = $this->createProject(
            'Completion transition test',
            now()->addDays(10)->toDateString(),
            (int) $assignedEmployee->employee_id
        );
        $assignmentId = $project['assignment_id'];

        Sanctum::actingAs($assignedEmployee);
        $this->postJson("/api/v1/project-assignments/{$assignmentId}/accept", [
            'decision' => 'accept',
        ])->assertForbidden();
        $this->postJson("/api/v1/project-assignments/{$assignmentId}/submit", [
            'submission_note' => "Completed requirements.\nAll acceptance checks passed.",
        ])->assertOk();
        $this->assertDatabaseHas('project_assignments', [
            'id' => $assignmentId,
            'status' => 'under_review',
            'progress_percent' => 100,
        ]);
        $this->postJson("/api/v1/project-assignments/{$assignmentId}/submit", [
            'submission_note' => 'Duplicate submission while under review.',
        ])->assertStatus(409);

        $reviewer = User::where('role', 'hr_admin')->firstOrFail();
        Sanctum::actingAs($reviewer);
        $this->postJson("/api/v1/project-assignments/{$assignmentId}/accept", [
            'decision' => 'return',
            'remarks' => "Please add one test.\nThen resubmit.",
        ])->assertOk();
        $this->assertDatabaseHas('project_assignments', [
            'id' => $assignmentId,
            'status' => 'in_progress',
            'returned_by' => $reviewer->name,
            'review_note' => "Please add one test.\nThen resubmit.",
        ]);
        Sanctum::actingAs($assignedEmployee);
        $returnedProject = collect($this->getJson('/api/v1/projects/mine')->assertOk()->json('data'))
            ->firstWhere('assignment_id', $assignmentId);
        $this->assertSame("Please add one test.\nThen resubmit.", $returnedProject['review_note']);
        $this->assertSame($reviewer->name, $returnedProject['returned_by']);

        $this->postJson("/api/v1/project-assignments/{$assignmentId}/submit", [
            'submission_note' => 'Added the requested test and verified the suite.',
        ])->assertOk();
        Sanctum::actingAs($reviewer);
        $this->postJson("/api/v1/project-assignments/{$assignmentId}/accept", [
            'decision' => 'accept',
            'remarks' => "Approved by HR.\nAll checks passed.",
        ])->assertOk();

        $this->assertDatabaseHas('project_assignments', [
            'id' => $assignmentId,
            'status' => 'completed',
            'accepted_by' => $reviewer->name,
            'review_note' => "Approved by HR.\nAll checks passed.",
        ]);
        $this->assertNotNull(DB::table('project_assignments')->where('id', $assignmentId)->value('actual_completed_at'));

        $listedProject = collect($this->getJson('/api/v1/projects')->assertOk()->json('data'))
            ->firstWhere('assignment_id', $assignmentId);
        $this->assertSame("Approved by HR.\nAll checks passed.", $listedProject['review_note']);
        $this->postJson("/api/v1/project-assignments/{$assignmentId}/accept", [
            'decision' => 'accept',
        ])->assertStatus(409);
    }

    public function test_project_penalty_notice_requires_hr_review_and_never_records_a_monetary_amount(): void
    {
        $manager = User::where('role', 'manager')->firstOrFail();
        $project = $this->createProject('HR-reviewed penalty notice', now()->subDay()->toDateString());
        $assignmentId = $project['assignment_id'];

        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/project-assignments/{$assignmentId}/penalties", [
            'reason' => 'Manager-only penalty attempt.',
        ])->assertForbidden();

        $reviewer = User::where('role', 'hr_admin')->firstOrFail();
        Sanctum::actingAs($reviewer);
        $response = $this->postJson("/api/v1/project-assignments/{$assignmentId}/penalties", [
            'reason' => 'HR reviewed the delay and documented the non-monetary notice.',
        ])->assertCreated();

        $this->assertSame('non_monetary', $response->json('data.penalty_type'));
        $this->assertNull($response->json('data.amount'));
        $this->assertSame($reviewer->name, $response->json('data.approved_by'));
        $this->assertSame(
            'HR reviewed the delay and documented the non-monetary notice.',
            $response->json('data.reason')
        );

        $activeProject = $this->createProject('Not yet overdue', now()->addDays(2)->toDateString());
        $this->postJson("/api/v1/project-assignments/{$activeProject['assignment_id']}/penalties", [
            'reason' => 'This project is not overdue.',
        ])->assertStatus(409);
        $this->assertDatabaseMissing('project_penalties', [
            'assignment_id' => $activeProject['assignment_id'],
        ]);
    }

    public function test_extension_and_progress_logs_identify_the_assigned_developer_and_on_behalf_actor(): void
    {
        $assignedEmployee = User::where('role', 'employee')->firstOrFail();
        $assignedProfile = DB::table('employees')
            ->where('id', $assignedEmployee->employee_id)
            ->first(['first_name', 'last_name']);
        $assignedName = trim($assignedProfile->first_name . ' ' . $assignedProfile->last_name);
        $project = $this->createProject(
            'Project request attribution test',
            now()->addDays(30)->toDateString(),
            (int) $assignedEmployee->employee_id
        );
        $assignmentId = $project['assignment_id'];

        Sanctum::actingAs($assignedEmployee);
        $this->postJson("/api/v1/project-assignments/{$assignmentId}/progress", [
            'progress_percent' => 40,
            'note' => 'Attribution progress note',
        ])->assertOk();
        $mine = collect($this->getJson('/api/v1/projects/mine')->assertOk()->json('data'))
            ->firstWhere('assignment_id', $assignmentId);
        $this->assertSame($assignedEmployee->name, $mine['updates'][0]['updated_by']);

        $employeeExtensionId = $this->postJson(
            "/api/v1/project-assignments/{$assignmentId}/extension-requests",
            [
                'requested_due_date' => now()->addDays(45)->toDateString(),
                'reason' => 'Developer submitted extension',
                'on_behalf_of' => 'Untrusted client value',
            ]
        )->assertCreated()->json('data.extension_id');
        $this->assertDatabaseHas('project_extension_requests', [
            'id' => $employeeExtensionId,
            'requested_by' => $assignedName,
            'on_behalf_of' => null,
        ]);

        $admin = User::where('role', 'super_admin')->firstOrFail();
        Sanctum::actingAs($admin);
        $this->postJson(
            "/api/v1/project-assignments/{$assignmentId}/extension-requests",
            [
                'requested_due_date' => now()->addDays(50)->toDateString(),
                'reason' => 'Admin submitted on the developer behalf',
            ]
        )->assertUnprocessable()->assertJsonValidationErrors('extension_request');

        Sanctum::actingAs(User::where('role', 'hr_admin')->firstOrFail());
        $this->postJson("/api/v1/project-extension-requests/{$employeeExtensionId}/approve", [
            'decision' => 'rejected',
            'decision_note' => 'Please provide additional details.',
        ])->assertOk();

        Sanctum::actingAs($admin);
        $adminExtensionId = $this->postJson(
            "/api/v1/project-assignments/{$assignmentId}/extension-requests",
            [
                'requested_due_date' => now()->addDays(50)->toDateString(),
                'reason' => 'Admin submitted on the developer behalf',
            ]
        )->assertCreated()->json('data.extension_id');

        $this->assertDatabaseHas('project_extension_requests', [
            'id' => $adminExtensionId,
            'requested_by' => $assignedName,
            'on_behalf_of' => $admin->name,
        ]);
        $listedProject = collect($this->getJson('/api/v1/projects')->assertOk()->json('data'))
            ->firstWhere('assignment_id', $assignmentId);
        $listedAdminExtension = collect($listedProject['extensions'])
            ->firstWhere('id', $adminExtensionId);
        $this->assertSame($assignedName, $listedAdminExtension['requested_by']);
        $this->assertSame($admin->name, $listedAdminExtension['on_behalf_of']);
    }

    public function test_extension_rules_preserve_the_baseline_and_record_decisions_in_history(): void
    {
        $assignedEmployee = User::where('role', 'employee')->firstOrFail();
        $baseline = now()->addDays(30)->toDateString();
        $project = $this->createProject(
            'Extension lifecycle validation',
            $baseline,
            (int) $assignedEmployee->employee_id
        );
        $assignmentId = $project['assignment_id'];

        Sanctum::actingAs($assignedEmployee);
        $this->postJson("/api/v1/project-assignments/{$assignmentId}/extension-requests", [
            'requested_due_date' => $baseline,
            'reason' => 'Must be later than the active deadline.',
        ])->assertUnprocessable()->assertJsonValidationErrors('requested_due_date');
        $this->postJson("/api/v1/project-assignments/{$assignmentId}/extension-requests", [
            'requested_due_date' => now()->addDays(29)->toDateString(),
            'reason' => 'Must be later than the active deadline.',
        ])->assertUnprocessable()->assertJsonValidationErrors('requested_due_date');

        $firstRequestId = $this->postJson("/api/v1/project-assignments/{$assignmentId}/extension-requests", [
            'requested_due_date' => now()->addDays(31)->toDateString(),
            'reason' => 'Additional time is needed.',
        ])->assertCreated()->json('data.extension_id');
        $this->postJson("/api/v1/project-assignments/{$assignmentId}/extension-requests", [
            'requested_due_date' => now()->addDays(32)->toDateString(),
            'reason' => 'A second pending request is not allowed.',
        ])->assertUnprocessable()->assertJsonValidationErrors('extension_request');

        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        Sanctum::actingAs($hrAdmin);
        $this->postJson("/api/v1/project-extension-requests/{$firstRequestId}/approve", [
            'decision' => 'rejected',
        ])->assertUnprocessable()->assertJsonValidationErrors('decision_note');
        $this->postJson("/api/v1/project-extension-requests/{$firstRequestId}/approve", [
            'decision' => 'rejected',
            'decision_note' => 'The proposed schedule needs clarification.',
        ])->assertOk();

        $rejectedRequest = DB::table('project_extension_requests')->where('id', $firstRequestId)->first();
        $this->assertSame('rejected', $rejectedRequest->status);
        $this->assertSame('The proposed schedule needs clarification.', $rejectedRequest->decision_note);
        $this->assertSame($hrAdmin->name, $rejectedRequest->decided_by);
        $this->assertNotNull($rejectedRequest->decided_at);
        $this->assertSame($baseline, DB::table('project_assignments')->where('id', $assignmentId)->value('due_date'));
        $this->assertSame($baseline, DB::table('project_assignments')->where('id', $assignmentId)->value('active_due_date'));

        Sanctum::actingAs($assignedEmployee);
        $approvedDate = now()->addDays(33)->toDateString();
        $approvedRequestId = $this->postJson("/api/v1/project-assignments/{$assignmentId}/extension-requests", [
            'requested_due_date' => $approvedDate,
            'reason' => 'A revised schedule is attached.',
        ])->assertCreated()->json('data.extension_id');
        Sanctum::actingAs($hrAdmin);
        $this->postJson("/api/v1/project-extension-requests/{$approvedRequestId}/approve", [
            'decision' => 'approved',
            'decision_note' => 'Approved after review.',
        ])->assertOk();

        $this->assertSame($baseline, DB::table('project_assignments')->where('id', $assignmentId)->value('due_date'));
        $this->assertSame($approvedDate, DB::table('project_assignments')->where('id', $assignmentId)->value('active_due_date'));
        $approvedRequest = DB::table('project_extension_requests')->where('id', $approvedRequestId)->first();
        $this->assertSame('approved', $approvedRequest->status);
        $this->assertSame($hrAdmin->name, $approvedRequest->decided_by);
        $this->assertNotNull($approvedRequest->decided_at);
        $this->assertSame(2, DB::table('audit_logs')
            ->where('action', 'PROJECT_EXTENSION_REVIEWED')
            ->where('module', 'Projects')
            ->count());

        $historyProject = collect($this->getJson('/api/v1/projects')->assertOk()->json('data'))
            ->firstWhere('assignment_id', $assignmentId);
        $this->assertSame(
            ['approved', 'rejected'],
            collect($historyProject['extensions'])->pluck('status')->all()
        );
    }

    public function test_project_assignment_model_rejects_changes_to_the_baseline_due_date(): void
    {
        $baseline = now()->addDays(20)->toDateString();
        $project = $this->createProject('Baseline immutability guard', $baseline);
        $assignment = ProjectAssignment::query()->findOrFail($project['assignment_id']);
        $assignment->due_date = now()->addDays(40)->toDateString();

        try {
            $assignment->save();
            $this->fail('Changing the baseline due date should throw an exception.');
        } catch (LogicException $exception) {
            $this->assertSame('The project baseline due date is immutable.', $exception->getMessage());
        }

        $this->assertSame(
            $baseline,
            DB::table('project_assignments')->where('id', $project['assignment_id'])->value('due_date')
        );
    }

    private function createProject(string $name, string $dueDate, ?int $employeeId = null): array
    {
        return $this->postJson('/api/v1/projects', [
            'name' => $name,
            'description' => 'Verify project state and deadline presentation.',
            'deliverable' => 'Completed project work.',
            'priority' => 'Medium',
            'assigned_employee_id' => $employeeId ?? DB::table('employees')
                ->where('status', 'active')
                ->value('id'),
            'start_date' => $dueDate,
            'planned_end_date' => $dueDate,
        ])->assertCreated()->json('data');
    }
}
