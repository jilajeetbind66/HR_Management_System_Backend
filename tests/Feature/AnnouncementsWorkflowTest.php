<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AnnouncementsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
    }

    public function test_branch_targeted_announcements_have_per_user_idempotent_read_receipts(): void
    {
        $branches = DB::table('branches')->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(2, $branches->count());
        $employeeOne = User::where('role', 'employee')->firstOrFail();
        $employeeTwo = User::where('role', 'employee')->where('id', '!=', $employeeOne->id)->firstOrFail();
        $anotherReader = User::where('role', 'hr_admin')->firstOrFail();
        DB::table('employees')->where('id', $employeeOne->employee_id)->update(['branch_id' => $branches[0]->id]);
        DB::table('employees')->where('id', $employeeTwo->employee_id)->update(['branch_id' => $branches[1]->id]);
        $admin = User::where('role', 'super_admin')->firstOrFail();

        Sanctum::actingAs($admin);
        $announcement = $this->postJson('/api/v1/announcements', [
            'title' => 'Branch safety notice',
            'content' => 'Review the updated evacuation route.',
            'category' => 'Policy',
            'target' => $branches[0]->name,
        ])->assertCreated()
            ->assertJsonPath('data.is_read', false)
            ->assertJsonPath('data.read_count', 0)
            ->json('data');

        Sanctum::actingAs($employeeOne);
        $this->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonPath('data.0.is_read', false);
        $this->postJson("/api/v1/announcements/{$announcement['id']}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true)
            ->assertJsonPath('data.read_count', 1);
        $this->postJson("/api/v1/announcements/{$announcement['id']}/read")
            ->assertOk()
            ->assertJsonPath('data.is_read', true)
            ->assertJsonPath('data.read_count', 1);

        Sanctum::actingAs($anotherReader);
        $this->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonPath('data.0.is_read', false)
            ->assertJsonPath('data.0.read_count', 1);

        Sanctum::actingAs($employeeTwo);
        $this->getJson('/api/v1/announcements')->assertOk()->assertExactJson(['data' => []]);
        $this->postJson("/api/v1/announcements/{$announcement['id']}/read")->assertNotFound();
    }

    public function test_announcement_routes_match_module_roles_and_hr_admin_can_publish_branch_notices(): void
    {
        $admin = User::where('role', 'hr_admin')->firstOrFail();
        $branch = DB::table('branches')->orderBy('id')->first();
        Sanctum::actingAs($admin);
        $announcement = $this->postJson('/api/v1/announcements', [
            'title' => 'Kaushambi team update',
            'content' => 'The branch schedule has been updated.',
            'category' => 'General',
            'target' => $branch->name,
        ])->assertCreated()->json('data');
        $this->assertSame($branch->name, $announcement['target']);

        $payrollAdmin = User::factory()->create(['role' => 'payroll_admin']);
        Sanctum::actingAs($payrollAdmin);
        $this->getJson('/api/v1/announcements')->assertForbidden();
        $this->postJson("/api/v1/announcements/{$announcement['id']}/read")->assertForbidden();
    }
}
