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

class TrainingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
    }

    public function test_training_assignment_progress_review_and_certificate_are_persisted(): void
    {
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        $manager = User::where('role', 'manager')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        DB::table('employees')
            ->where('id', $employee->employee_id)
            ->update(['reporting_manager_id' => $manager->employee_id]);
        $extraEmployeeId = DB::table('employees')
            ->where('status', 'active')
            ->whereNotIn('id', [$employee->employee_id, $manager->employee_id])
            ->value('id');

        Sanctum::actingAs($hrAdmin);
        $courseResponse = $this->postJson('/api/v1/training/courses', [
            'title' => 'Information Security Basics',
            'category' => 'Security',
            'description' => 'Annual security awareness course.',
            'mandatory' => true,
            'due_date' => '2027-01-31',
            'employee_ids' => [$employee->employee_id, $manager->employee_id, $extraEmployeeId],
        ])->assertCreated()
            ->assertJsonPath('data.course.title', 'Information Security Basics')
            ->assertJsonPath('data.course.assigned_count', 3);

        $courseId = $courseResponse->json('data.course.id');
        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $employee->id,
            'event_key' => 'training.assigned',
            'title' => 'New training assigned',
        ]);
        $employeeEnrollmentId = collect($courseResponse->json('data.enrollments'))
            ->firstWhere('employee_id', $employee->employee_id)['id'];

        Sanctum::actingAs($employee);
        $employeeTraining = $this->getJson('/api/v1/training')
            ->assertOk()
            ->assertJsonCount(1, 'data.enrollments');
        $this->assertSame(
            [$courseId],
            array_column($employeeTraining->json('data.courses'), 'id')
        );
        $employeeTraining->assertJsonPath('data.enrollments.0.employee_id', $employee->employee_id);

        $this->patchJson("/api/v1/training/enrollments/{$employeeEnrollmentId}/progress", [
            'progress_percent' => 100,
        ])->assertOk()
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.progress_percent', 100);
        $this->postJson("/api/v1/training/enrollments/{$employeeEnrollmentId}/review", [
            'decision' => 'approved',
        ])->assertForbidden();

        Sanctum::actingAs($manager);
        $managerTraining = $this->getJson('/api/v1/training')->assertOk();
        $this->assertCount(2, $managerTraining->json('data.enrollments'));
        $this->assertSame(
            [$courseId],
            array_column($managerTraining->json('data.courses'), 'id')
        );
        $this->postJson("/api/v1/training/enrollments/{$employeeEnrollmentId}/review", [
            'decision' => 'returned',
            'review_note' => 'Please complete the final quiz.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_progress')
            ->assertJsonPath('data.review_note', 'Please complete the final quiz.');

        Sanctum::actingAs($employee);
        $this->patchJson("/api/v1/training/enrollments/{$employeeEnrollmentId}/progress", [
            'progress_percent' => 100,
        ])->assertOk()
            ->assertJsonPath('data.status', 'pending_review')
            ->assertJsonPath('data.review_note', null);

        Sanctum::actingAs($manager);
        $approved = $this->postJson("/api/v1/training/enrollments/{$employeeEnrollmentId}/review", [
            'decision' => 'approved',
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->json('data');

        $this->assertStringStartsWith('TRN-', $approved['certificate_code']);
        $this->assertDatabaseHas('training_enrollments', [
            'id' => $employeeEnrollmentId,
            'course_id' => $courseId,
            'status' => 'completed',
            'certificate_code' => $approved['certificate_code'],
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'TRAINING_COMPLETION_REVIEWED',
            'module' => 'Training',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'TRAINING_COMPLETION_SUBMITTED',
            'module' => 'Training',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'TRAINING_COMPLETION_VERIFIED',
            'module' => 'Training',
        ]);

        Sanctum::actingAs($employee);
        $this->patchJson("/api/v1/training/enrollments/{$employeeEnrollmentId}/progress", [
            'progress_percent' => 100,
        ])->assertStatus(409);
    }

    public function test_training_management_and_review_are_role_gated(): void
    {
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        $hrExecutive = User::factory()->create([
            'name' => 'Training HR Executive',
            'email' => 'training.executive@example.com',
            'role' => 'hr_executive',
        ]);
        $employee = User::where('role', 'employee')->firstOrFail();

        Sanctum::actingAs($hrExecutive);
        $this->getJson('/api/v1/training')->assertOk();
        $this->postJson('/api/v1/training/courses', [])->assertForbidden();
        $this->patchJson('/api/v1/training/enrollments/1/progress', [])->assertForbidden();
        $this->postJson('/api/v1/training/enrollments/1/review', [])->assertForbidden();

        Sanctum::actingAs($hrAdmin);
        $this->postJson('/api/v1/training/courses', [
            'title' => 'Workplace Conduct',
            'category' => 'Compliance',
            'mandatory' => true,
            'due_date' => '2027-02-01',
            'employee_ids' => [$employee->employee_id],
        ])->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'TRAINING_COURSE_CREATED',
            'module' => 'Training',
        ]);
    }

    public function test_a_training_completion_cannot_be_approved_by_the_enrolled_employee(): void
    {
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();

        Sanctum::actingAs($hrAdmin);
        $course = $this->postJson('/api/v1/training/courses', [
            'title' => 'Workplace Safety',
            'category' => 'Compliance',
            'mandatory' => true,
            'due_date' => '2027-02-01',
            'employee_ids' => [$employee->employee_id],
        ])->assertCreated()->json('data');
        $enrollmentId = $course['enrollments'][0]['id'];
        DB::table('training_enrollments')
            ->where('id', $enrollmentId)
            ->update(['status' => 'pending_review']);
        DB::table('users')->where('id', $employee->id)->update(['role' => 'hr_admin']);

        Sanctum::actingAs($employee->fresh());
        $this->postJson("/api/v1/training/enrollments/{$enrollmentId}/review", [
            'decision' => 'approved',
        ])->assertForbidden();
    }

    public function test_mandatory_due_dates_duplicate_assignments_and_course_lifecycle_are_enforced(): void
    {
        Carbon::setTestNow('2026-11-18 10:00:00');
        try {
            $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
            $employee = User::where('role', 'employee')->firstOrFail();
            $manager = User::where('role', 'manager')->firstOrFail();
            Sanctum::actingAs($hrAdmin);

            $baseCourse = [
                'title' => 'Required Data Privacy',
                'category' => 'Compliance',
                'mandatory' => true,
                'employee_ids' => [$employee->employee_id],
            ];
            $this->postJson('/api/v1/training/courses', $baseCourse)
                ->assertStatus(422)
                ->assertJsonValidationErrors(['due_date']);
            $this->postJson('/api/v1/training/courses', [
                ...$baseCourse,
                'due_date' => '2026-11-17',
            ])->assertStatus(422)->assertJsonValidationErrors(['due_date']);

            $course = $this->postJson('/api/v1/training/courses', [
                ...$baseCourse,
                'due_date' => '2026-11-21',
                'material_url' => 'https://training.example.com/privacy',
            ])->assertCreated()
                ->assertJsonPath('data.course.material_url', 'https://training.example.com/privacy')
                ->json('data');
            $courseId = $course['course']['id'];
            $this->postJson('/api/v1/uploads', [
                'module' => 'training_material',
                'record_id' => $courseId,
                'file' => UploadedFile::fake()->create('not-a-pdf.exe', 5, 'application/octet-stream'),
            ])->assertStatus(422)->assertJsonValidationErrors(['file']);

            $this->postJson("/api/v1/training/courses/{$courseId}/enrollments", [
                'employee_ids' => [$employee->employee_id],
            ])->assertStatus(422)->assertJsonValidationErrors(['employee_ids']);
            $this->postJson("/api/v1/training/courses/{$courseId}/enrollments", [
                'employee_ids' => [$manager->employee_id],
            ])->assertOk()->assertJsonPath('data.course.assigned_count', 2);

            $this->deleteJson("/api/v1/training/courses/{$courseId}")->assertStatus(409);
            $this->patchJson("/api/v1/training/courses/{$courseId}", [
                'status' => 'archived',
            ])->assertOk()->assertJsonPath('data.course.status', 'archived');
            $this->postJson("/api/v1/training/courses/{$courseId}/enrollments", [
                'employee_ids' => [DB::table('employees')
                    ->whereNotIn('id', [$employee->employee_id, $manager->employee_id])
                    ->where('status', 'active')
                    ->value('id')],
            ])->assertStatus(409);

            Sanctum::actingAs($employee);
            $enrollmentId = $course['enrollments'][0]['id'];
            $this->patchJson("/api/v1/training/enrollments/{$enrollmentId}/progress", [
                'progress_percent' => 50,
            ])->assertStatus(409);

            Sanctum::actingAs($hrAdmin);
            $this->patchJson("/api/v1/training/courses/{$courseId}", [
                'status' => 'active',
            ])->assertOk();
            $this->patchJson("/api/v1/training/courses/{$courseId}", [
                'material_url' => 'javascript:alert(1)',
            ])->assertStatus(422)->assertJsonValidationErrors(['material_url']);

            $emptyCourseId = DB::table('training_courses')->insertGetId([
                'title' => 'Unassigned draft',
                'category' => 'Other',
                'mandatory' => false,
                'status' => 'active',
                'created_by_user_id' => $hrAdmin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->deleteJson("/api/v1/training/courses/{$emptyCourseId}")->assertOk();
            $this->assertDatabaseMissing('training_courses', ['id' => $emptyCourseId]);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_due_reminders_are_idempotent_and_overdue_alerts_reach_the_manager(): void
    {
        Carbon::setTestNow('2026-11-18 08:00:00');
        try {
            $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
            $employee = User::where('role', 'employee')->firstOrFail();
            $manager = User::where('role', 'manager')->firstOrFail();
            DB::table('employees')->where('id', $employee->employee_id)
                ->update(['reporting_manager_id' => $manager->employee_id]);
            Sanctum::actingAs($hrAdmin);
            $this->postJson('/api/v1/training/courses', [
                'title' => 'Upcoming Security Course',
                'category' => 'Security',
                'mandatory' => true,
                'due_date' => '2026-11-20',
                'employee_ids' => [$employee->employee_id],
            ])->assertCreated();

            $overdueCourseId = DB::table('training_courses')->insertGetId([
                'title' => 'Overdue Compliance Course',
                'category' => 'Compliance',
                'mandatory' => true,
                'due_date' => '2026-11-17',
                'status' => 'active',
                'created_by_user_id' => $hrAdmin->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('training_enrollments')->insert([
                'course_id' => $overdueCourseId,
                'employee_id' => $employee->employee_id,
                'assigned_by_user_id' => $hrAdmin->id,
                'status' => 'in_progress',
                'progress_percent' => 50,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Artisan::call('hrms:training-notifications');
            Artisan::call('hrms:training-notifications');

            $this->assertSame(1, DB::table('user_notifications')
                ->where('user_id', $employee->id)
                ->where('event_key', 'training.due_reminder')
                ->count());
            $this->assertSame(1, DB::table('user_notifications')
                ->where('user_id', $manager->id)
                ->where('event_key', 'training.overdue')
                ->count());
            $this->assertSame(1, DB::table('training_notification_dispatches')
                ->where('enrollment_id', DB::table('training_enrollments')
                    ->where('course_id', $overdueCourseId)->value('id'))
                ->count());

            Sanctum::actingAs($manager);
            $trainingResponse = $this->getJson('/api/v1/training')->assertOk();
            $this->assertSame(1, $trainingResponse->json('meta.team_training.overdue_count'));
            $this->getJson('/api/v1/reports/summary')
                ->assertOk()
                ->assertJsonStructure(['data' => ['training' => ['branch_compliance']]]);
            $this->getJson('/api/v1/reports/exports/training_compliance')
                ->assertOk()
                ->assertJsonPath('data.report', 'training_compliance');
        } finally {
            Carbon::setTestNow();
        }
    }
}
