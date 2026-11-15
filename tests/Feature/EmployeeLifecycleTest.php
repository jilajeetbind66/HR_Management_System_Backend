<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
    }

    public function test_hr_can_create_and_complete_a_persisted_onboarding_checklist(): void
    {
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        $employeeId = DB::table('employees')->where('status', 'active')->value('id');
        Sanctum::actingAs($hrAdmin);

        $response = $this->postJson('/api/v1/lifecycle/cases', [
            'employee_id' => $employeeId,
            'case_type' => 'onboarding',
            'effective_date' => '2027-01-01',
            'reason' => 'New employee induction',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.target_completion_date', '2027-01-31')
            ->assertJsonPath('data.tasks.0.due_date', '2027-01-02')
            ->assertJsonPath('data.tasks.3.due_date', '2027-01-31');

        $case = $response->json('data');
        $this->assertCount(4, $case['tasks']);
        $this->assertDatabaseHas('employee_lifecycle_cases', [
            'id' => $case['id'],
            'employee_id' => $employeeId,
            'case_type' => 'onboarding',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'LIFECYCLE_CASE_CREATED',
            'module' => 'Employee Lifecycle',
        ]);

        $this->patchJson("/api/v1/lifecycle/tasks/{$case['tasks'][0]['id']}", [
            'status' => 'waived',
        ])->assertUnprocessable();

        foreach ($case['tasks'] as $task) {
            $this->patchJson("/api/v1/lifecycle/tasks/{$task['id']}", [
                'status' => 'completed',
                'notes' => 'Completed and verified.',
            ])->assertOk();
        }

        $this->assertDatabaseHas('employee_lifecycle_cases', [
            'id' => $case['id'],
            'status' => 'completed',
        ]);
        $this->patchJson("/api/v1/lifecycle/tasks/{$case['tasks'][0]['id']}", [
            'status' => 'pending',
        ])->assertStatus(409);
    }

    public function test_offboarding_requires_a_valid_notice_date_and_tracks_clearance_tasks(): void
    {
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        $employeeId = DB::table('employees')->where('status', 'active')->value('id');
        Sanctum::actingAs($hrAdmin);

        $this->postJson('/api/v1/lifecycle/cases', [
            'employee_id' => $employeeId,
            'case_type' => 'offboarding',
            'effective_date' => '2027-01-01',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('notice_date');

        $this->postJson('/api/v1/lifecycle/cases', [
            'employee_id' => $employeeId,
            'case_type' => 'offboarding',
            'notice_date' => '2027-01-02',
            'effective_date' => '2027-01-01',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('notice_date');

        $this->postJson('/api/v1/lifecycle/cases', [
            'employee_id' => $employeeId,
            'case_type' => 'offboarding',
            'notice_date' => '2026-12-01',
            'effective_date' => '2027-01-01',
        ])->assertCreated()
            ->assertJsonPath('data.notice_date', '2026-12-01')
            ->assertJsonPath('data.tasks.0.department', 'Manager')
            ->assertJsonPath('data.tasks.1.department', 'IT')
            ->assertJsonPath('data.tasks.3.department', 'Admin')
            ->assertJsonPath('data.tasks.4.department', 'HR');
    }

    public function test_lifecycle_access_is_limited_to_hr_roles_and_hr_executives_are_read_only(): void
    {
        $employee = User::where('role', 'employee')->firstOrFail();
        $hrExecutive = User::factory()->create([
            'name' => 'HR Executive',
            'email' => 'lifecycle.executive@example.com',
            'role' => 'hr_executive',
        ]);
        $employeeId = DB::table('employees')->where('status', 'active')->value('id');

        Sanctum::actingAs($employee);
        $this->getJson('/api/v1/lifecycle/cases')->assertForbidden();
        $this->postJson('/api/v1/lifecycle/cases', [])->assertForbidden();

        Sanctum::actingAs($hrExecutive);
        $this->getJson('/api/v1/lifecycle/cases')->assertOk();
        $this->postJson('/api/v1/lifecycle/cases', [
            'employee_id' => $employeeId,
            'case_type' => 'onboarding',
            'effective_date' => '2027-01-01',
        ])->assertForbidden();
        $this->patchJson('/api/v1/lifecycle/tasks/1', ['status' => 'completed'])->assertForbidden();

        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        Sanctum::actingAs($hrAdmin);
        $payload = [
            'employee_id' => $employeeId,
            'case_type' => 'onboarding',
            'effective_date' => '2027-01-01',
        ];
        $this->postJson('/api/v1/lifecycle/cases', $payload)->assertCreated();
        $this->postJson('/api/v1/lifecycle/cases', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('employee_id');
    }
}
