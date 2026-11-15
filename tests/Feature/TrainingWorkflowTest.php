<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
