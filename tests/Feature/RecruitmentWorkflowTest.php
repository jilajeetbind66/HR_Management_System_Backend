<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RecruitmentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
    }

    public function test_interview_evaluations_are_stage_guarded_and_attributed(): void
    {
        $candidate = $this->createCandidate();

        $this->patchJson("/api/v1/recruitment/candidates/{$candidate['id']}/evaluation", [
            'rating' => 4,
            'evaluation_note' => 'Clear communication and strong technical fundamentals.',
        ])->assertStatus(409);

        $this->patchJson("/api/v1/recruitment/candidates/{$candidate['id']}/stage", [
            'stage' => 'Interview',
        ])->assertOk();

        $admin = User::where('role', 'super_admin')->firstOrFail();
        $this->patchJson("/api/v1/recruitment/candidates/{$candidate['id']}/evaluation", [
            'rating' => 4.5,
            'evaluation_note' => 'Clear communication and strong technical fundamentals.',
        ])->assertOk()
            ->assertJsonPath('data.rating', 4.5)
            ->assertJsonPath('data.evaluation_note', 'Clear communication and strong technical fundamentals.')
            ->assertJsonPath('data.evaluated_by_user_id', $admin->id);

        $this->assertDatabaseHas('recruitment_candidates', [
            'id' => $candidate['id'],
            'rating' => 4.5,
            'evaluated_by_user_id' => $admin->id,
        ]);

        $this->patchJson("/api/v1/recruitment/candidates/{$candidate['id']}/stage", [
            'stage' => 'Offered',
        ])->assertOk();
        $this->postJson("/api/v1/recruitment/candidates/{$candidate['id']}/convert")->assertCreated();
        $this->patchJson("/api/v1/recruitment/candidates/{$candidate['id']}/evaluation", [
            'rating' => 5,
            'evaluation_note' => 'This evaluation must not overwrite the converted candidate record.',
        ])->assertStatus(409);
    }

    public function test_closed_requisition_rejects_new_candidates_until_reopened(): void
    {
        $job = $this->createJob();

        $this->patchJson("/api/v1/recruitment/jobs/{$job['id']}/status", [
            'status' => 'Closed',
        ])->assertOk()->assertJsonPath('data.status', 'Closed');

        $this->postJson('/api/v1/recruitment/candidates', [
            'job_id' => $job['id'],
            'first_name' => 'Closed',
            'last_name' => 'Applicant',
            'email' => 'closed.applicant@example.test',
        ])->assertStatus(409);

        $this->patchJson("/api/v1/recruitment/jobs/{$job['id']}/status", [
            'status' => 'Open',
        ])->assertOk()->assertJsonPath('data.status', 'Open');

        $this->assertDatabaseHas('recruitment_jobs', [
            'id' => $job['id'],
            'status' => 'Open',
        ]);

        $this->postJson('/api/v1/recruitment/candidates', [
            'job_id' => $job['id'],
            'first_name' => 'Reopened',
            'last_name' => 'Applicant',
            'email' => 'reopened.applicant@example.test',
        ])->assertCreated();
    }

    public function test_hr_executive_can_view_recruitment_but_cannot_mutate_it(): void
    {
        $candidate = $this->createCandidate();
        $this->patchJson("/api/v1/recruitment/candidates/{$candidate['id']}/stage", [
            'stage' => 'Interview',
        ])->assertOk();

        $executive = User::factory()->create(['role' => 'hr_executive']);
        Sanctum::actingAs($executive);

        $this->getJson('/api/v1/recruitment')->assertOk();
        $this->patchJson("/api/v1/recruitment/candidates/{$candidate['id']}/evaluation", [
            'rating' => 5,
            'evaluation_note' => 'Not authorized.',
        ])->assertForbidden();
        $this->patchJson("/api/v1/recruitment/candidates/{$candidate['id']}/stage", [
            'stage' => 'Offered',
        ])->assertForbidden();
    }

    private function createCandidate(): array
    {
        $job = $this->createJob();

        return $this->postJson('/api/v1/recruitment/candidates', [
            'job_id' => $job['id'],
            'first_name' => 'Asha',
            'last_name' => 'Rao',
            'email' => 'asha.rao@example.test',
        ])->assertCreated()->json('data');
    }

    private function createJob(): array
    {
        return $this->postJson('/api/v1/recruitment/jobs', [
            'title' => 'Backend Engineer',
            'department' => 'Engineering',
            'branch_id' => DB::table('branches')->value('id'),
            'vacancies' => 1,
        ])->assertCreated()->json('data');
    }
}
