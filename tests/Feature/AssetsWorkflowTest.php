<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssetsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
    }

    public function test_super_admin_can_register_allocate_return_and_change_asset_status(): void
    {
        $admin = User::where('role', 'super_admin')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        $employeeRecord = DB::table('employees')->where('id', $employee->employee_id)->first();
        $this->assertNotNull($employeeRecord);
        $branchId = $employeeRecord->branch_id;
        Sanctum::actingAs($admin);

        $asset = $this->postJson('/api/v1/assets', [
            'name' => 'Dell Latitude',
            'category' => 'Laptop',
            'serial_number' => 'DL-TEST-1001',
            'branch_id' => $branchId,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'Available')
            ->assertJsonPath('data.assigned_to', null)
            ->json('data');
        $this->assertStringStartsWith('AST-', $asset['asset_code']);

        $this->postJson("/api/v1/assets/{$asset['id']}/assign", [
            'employee_id' => $employee->employee_id,
        ])->assertOk()
            ->assertJsonPath('data.status', 'Allocated')
            ->assertJsonPath('data.assigned_employee_id', $employee->employee_id)
            ->assertJsonPath('data.assigned_employee_code', $employeeRecord->employee_code);
        $this->postJson("/api/v1/assets/{$asset['id']}/assign", [
            'employee_id' => $employee->employee_id,
        ])->assertStatus(409);

        $this->postJson("/api/v1/assets/{$asset['id']}/return")
            ->assertOk()
            ->assertJsonPath('data.status', 'Available')
            ->assertJsonPath('data.assigned_employee_id', null)
            ->assertJsonPath('data.assigned_to', null);

        $this->patchJson("/api/v1/assets/{$asset['id']}/status", [
            'status' => 'Maintenance',
        ])->assertOk()->assertJsonPath('data.status', 'Maintenance');
        $this->postJson("/api/v1/assets/{$asset['id']}/assign", [
            'employee_id' => $employee->employee_id,
        ])->assertStatus(409);
        $this->patchJson("/api/v1/assets/{$asset['id']}/status", [
            'status' => 'Available',
        ])->assertOk()->assertJsonPath('data.status', 'Available');
        $this->patchJson("/api/v1/assets/{$asset['id']}/status", [
            'status' => 'Retired',
        ])->assertOk()->assertJsonPath('data.status', 'Retired');
        $this->patchJson("/api/v1/assets/{$asset['id']}/status", [
            'status' => 'Available',
        ])->assertStatus(409);
    }

    public function test_only_super_admin_can_access_the_asset_register_and_duplicate_serials_are_rejected(): void
    {
        $admin = User::where('role', 'super_admin')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        $branchId = DB::table('branches')->value('id');
        Sanctum::actingAs($admin);
        $payload = [
            'name' => 'Monitor',
            'category' => 'Display',
            'serial_number' => 'MON-TEST-1001',
            'branch_id' => $branchId,
        ];
        $this->postJson('/api/v1/assets', $payload)->assertCreated();
        $this->postJson('/api/v1/assets', $payload)->assertUnprocessable();

        Sanctum::actingAs($employee);
        $this->getJson('/api/v1/assets')->assertForbidden();
        $this->postJson('/api/v1/assets', $payload)->assertForbidden();
    }
}
