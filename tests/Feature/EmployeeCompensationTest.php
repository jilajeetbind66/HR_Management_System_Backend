<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class EmployeeCompensationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
    }

    public function test_super_admin_and_hr_admin_can_update_compensation_and_bank_details_with_audit_history(): void
    {
        $employeeId = (int) DB::table('employees')->where('employee_code', 'EMP-005')->value('id');
        $updates = [
            [
                'role' => 'super_admin',
                'payload' => [
                    'ctc_annual' => 900000,
                    'gross_monthly' => 75000,
                    'basic_monthly' => 37500,
                    'bank_name' => 'Test Bank',
                    'account_number' => '123456789012',
                    'ifsc_code' => 'abcd0123456',
                ],
            ],
            [
                'role' => 'hr_admin',
                'payload' => [
                    'ctc_annual' => 960000,
                    'gross_monthly' => 80000,
                    'basic_monthly' => 40000,
                    'bank_name' => 'Updated Test Bank',
                    'account_number' => '987654321012',
                    'ifsc_code' => 'wxyz0123456',
                ],
            ],
        ];

        foreach ($updates as $update) {
            Sanctum::actingAs(User::where('role', $update['role'])->firstOrFail());
            $response = $this->patchJson("/api/v1/employees/{$employeeId}/compensation", $update['payload'])
                ->assertOk()
                ->assertJsonPath('data.ifsc_code', strtoupper($update['payload']['ifsc_code']));

            $this->assertSame(
                (float) $update['payload']['ctc_annual'],
                (float) $response->json('data.ctc_annual')
            );
            $this->assertSame(
                (float) $update['payload']['gross_monthly'],
                (float) $response->json('data.gross_monthly')
            );
            $this->assertSame(
                (float) $update['payload']['basic_monthly'],
                (float) $response->json('data.basic_monthly')
            );
            $this->assertDatabaseHas('employee_statutory_details', [
                'employee_id' => $employeeId,
                ...$update['payload'],
                'ifsc_code' => strtoupper($update['payload']['ifsc_code']),
            ]);

            $employees = $this->getJson('/api/v1/employees')->assertOk()->json('data');
            $employee = collect($employees)->firstWhere('id', $employeeId);
            $this->assertSame((float) $update['payload']['ctc_annual'], (float) $employee['ctc_annual']);
            $this->assertSame($update['payload']['account_number'], $employee['account_number']);
        }

        $this->assertSame(2, DB::table('audit_logs')
            ->where('action', 'EMPLOYEE_COMPENSATION_UPDATED')
            ->count());
        $auditDescription = DB::table('audit_logs')
            ->where('action', 'EMPLOYEE_COMPENSATION_UPDATED')
            ->orderByDesc('id')
            ->value('description');
        $this->assertStringNotContainsString('987654321012', $auditDescription);
    }

    public function test_compensation_update_rejects_unauthorized_roles_invalid_salary_order_and_invalid_ifsc(): void
    {
        $employeeId = (int) DB::table('employees')->where('employee_code', 'EMP-005')->value('id');
        $payload = [
            'ctc_annual' => 600000,
            'gross_monthly' => 50000,
            'basic_monthly' => 25000,
            'bank_name' => 'Test Bank',
            'account_number' => '123456789012',
            'ifsc_code' => 'TEST0000001',
        ];

        Sanctum::actingAs(User::where('role', 'manager')->firstOrFail());
        $this->patchJson("/api/v1/employees/{$employeeId}/compensation", $payload)->assertForbidden();

        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $this->patchJson("/api/v1/employees/{$employeeId}/compensation", [
            ...$payload,
            'basic_monthly' => 50001,
        ])->assertUnprocessable()->assertJsonValidationErrors('basic_monthly');

        $this->patchJson("/api/v1/employees/{$employeeId}/compensation", [
            ...$payload,
            'ifsc_code' => 'INVALID',
        ])->assertUnprocessable()->assertJsonValidationErrors('ifsc_code');
    }
}
