<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PerformanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_quarterly_objectives_require_full_weighting_and_are_team_scoped(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
        $admin = User::where('role', 'super_admin')->firstOrFail();
        $manager = User::where('role', 'manager')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        $otherEmployee = User::where('role', 'employee')->where('id', '!=', $employee->id)->firstOrFail();
        DB::table('employees')
            ->where('id', $employee->employee_id)
            ->update(['reporting_manager_id' => $manager->employee_id]);
        Sanctum::actingAs($admin);
        $cycle = $this->postJson('/api/v1/performance/cycles', [
            'year' => 2026,
            'quarter' => 4,
        ])->assertCreated()
            ->assertJsonPath('data.starts_on', '2026-10-01')
            ->assertJsonPath('data.ends_on', '2026-12-31')
            ->json('data');
        $this->postJson('/api/v1/performance/cycles', [
            'year' => 2026,
            'quarter' => 4,
        ])->assertUnprocessable();
        Sanctum::actingAs($employee);

        $firstObjective = $this->createObjective($cycle['id'], 60, 'Improve release quality', 'Reduce escaped defects');
        $secondObjective = $this->createObjective($cycle['id'], 30, 'Improve delivery visibility', 'Publish weekly status');
        $this->postJson("/api/v1/performance/cycles/{$cycle['id']}/submit-objectives")
            ->assertUnprocessable();
        $thirdObjective = $this->createObjective($cycle['id'], 10, 'Improve team enablement', 'Document common workflows');
        $this->postJson("/api/v1/performance/cycles/{$cycle['id']}/submit-objectives")
            ->assertOk()
            ->assertJsonPath('data.0.status', 'submitted');

        Sanctum::actingAs($otherEmployee);
        $otherGoal = $this->createObjective($cycle['id'], 100, 'Other employee objective', 'Complete onboarding');
        $this->postJson("/api/v1/performance/cycles/{$cycle['id']}/submit-objectives")->assertOk();
        Sanctum::actingAs($manager);
        $this->postJson('/api/v1/performance/cycles', [
            'year' => 2027,
            'quarter' => 1,
        ])->assertForbidden();
        $teamGoals = $this->getJson('/api/v1/performance')->assertOk()->json('data.objectives');
        $this->assertSame(
            [$firstObjective['id'], $secondObjective['id'], $thirdObjective['id']],
            array_column($teamGoals, 'id')
        );
        $this->patchJson("/api/v1/performance/key-results/{$otherGoal['key_results'][0]['id']}/progress", [
            'progress_percent' => 50,
        ])->assertForbidden();
        $this->postJson("/api/v1/performance/objectives/{$otherGoal['id']}/review", [
            'decision' => 'approved',
        ])->assertForbidden();
        $this->postJson("/api/v1/performance/objectives/{$firstObjective['id']}/review", [
            'decision' => 'returned',
        ])->assertUnprocessable();
        $this->postJson("/api/v1/performance/objectives/{$firstObjective['id']}/review", [
            'decision' => 'returned',
            'manager_note' => 'Make the target measurable.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'returned');

        Sanctum::actingAs($employee);
        $this->patchJson("/api/v1/performance/objectives/{$firstObjective['id']}", [
            'title' => 'Improve release quality',
            'description' => 'Track defects across each release.',
            'weight_percent' => 60,
            'key_results' => [['title' => 'Reduce escaped defects']],
        ])->assertOk()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.manager_note', null);
        $this->postJson("/api/v1/performance/cycles/{$cycle['id']}/submit-objectives")->assertOk();

        Sanctum::actingAs($manager);
        $visibleGoals = $this->getJson('/api/v1/performance')->assertOk()->json('data.objectives');
        $this->assertSame(
            [$firstObjective['id'], $secondObjective['id'], $thirdObjective['id']],
            array_column($visibleGoals, 'id')
        );
        $this->postJson("/api/v1/performance/objectives/{$firstObjective['id']}/review", [
            'decision' => 'approved',
        ])->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        Sanctum::actingAs($hrAdmin);
        $this->getJson('/api/v1/performance')->assertForbidden();
    }

    public function test_ratings_open_after_quarter_end_and_cycle_close_locks_records(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00'));
        $admin = User::where('role', 'super_admin')->firstOrFail();
        $manager = User::where('role', 'manager')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        DB::table('employees')
            ->where('id', $employee->employee_id)
            ->update(['reporting_manager_id' => $manager->employee_id]);

        Sanctum::actingAs($admin);
        $cycle = $this->postJson('/api/v1/performance/cycles', [
            'year' => 2026,
            'quarter' => 3,
        ])->assertCreated()->json('data');
        Sanctum::actingAs($employee);
        $objective = $this->createObjective($cycle['id'], 100, 'Deliver quarterly outcome', 'Complete launch milestone');
        $this->postJson("/api/v1/performance/cycles/{$cycle['id']}/submit-objectives")->assertOk();

        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/performance/objectives/{$objective['id']}/review", [
            'decision' => 'approved',
        ])->assertOk();
        Sanctum::actingAs($employee);
        $this->patchJson("/api/v1/performance/objectives/{$objective['id']}/self-review", [
            'rating' => 4,
        ])->assertStatus(409);

        Carbon::setTestNow(Carbon::parse('2026-10-01 12:00:00'));
        Sanctum::actingAs($manager);
        $this->patchJson("/api/v1/performance/objectives/{$objective['id']}/manager-review", [
            'rating' => 4,
        ])->assertStatus(409);
        Sanctum::actingAs($employee);
        $this->patchJson("/api/v1/performance/objectives/{$objective['id']}/self-review", [
            'rating' => 4,
            'review_note' => 'Delivered the milestone.',
        ])->assertOk()
            ->assertJsonPath('data.self_rating', 4);
        Sanctum::actingAs($manager);
        $this->patchJson("/api/v1/performance/objectives/{$objective['id']}/manager-review", [
            'rating' => 5,
            'review_note' => 'Strong delivery.',
        ])->assertOk()
            ->assertJsonPath('data.manager_rating', 5);

        Sanctum::actingAs($admin);
        $this->postJson("/api/v1/performance/cycles/{$cycle['id']}/close")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');
        Sanctum::actingAs($employee);
        $this->patchJson("/api/v1/performance/key-results/{$objective['key_results'][0]['id']}/progress", [
            'progress_percent' => 80,
        ])->assertStatus(409);
    }

    public function test_missing_key_result_returns_not_found(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 12:00:00'));
        $employee = User::where('role', 'employee')->firstOrFail();
        Sanctum::actingAs($employee);
        $this->getJson('/api/v1/performance')->assertOk();
        $this->patchJson('/api/v1/performance/key-results/1/progress', [
            'progress_percent' => 50,
        ])->assertNotFound();
    }

    private function createObjective(int $cycleId, int $weight, string $title, string $keyResultTitle): array
    {
        return $this->postJson("/api/v1/performance/cycles/{$cycleId}/objectives", [
            'title' => $title,
            'description' => null,
            'weight_percent' => $weight,
            'key_results' => [['title' => $keyResultTitle]],
        ])->assertCreated()->json('data');
    }
}
