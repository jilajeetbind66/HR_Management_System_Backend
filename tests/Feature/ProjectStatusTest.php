<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
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
        $this->assertSame(0, $projects->firstWhere('id', $reviewProject['id'])['overdue_days']);

        $overdueResults = collect($this->getJson('/api/v1/projects?status=overdue')
            ->assertOk()
            ->json('data'));
        $this->assertSame([$overdueProject['id']], $overdueResults->pluck('id')->all());
        $inProgressResults = $this->getJson('/api/v1/projects?status=in_progress')
            ->assertOk()
            ->json('data');
        $this->assertNotContains($overdueProject['id'], collect($inProgressResults)->pluck('id')->all());
    }

    private function createProject(string $name, string $dueDate): array
    {
        return $this->postJson('/api/v1/projects', [
            'name' => $name,
            'description' => 'Verify project state and deadline presentation.',
            'deliverable' => 'Completed project work.',
            'priority' => 'Medium',
            'assigned_employee_id' => DB::table('employees')->value('id'),
            'start_date' => $dueDate,
            'planned_end_date' => $dueDate,
        ])->assertCreated()->json('data');
    }
}
