<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendancePunchTest extends TestCase
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

    public function test_punch_in_uses_india_date_and_starts_with_zero_work_hours(): void
    {
        $employee = User::where('role', 'employee')->firstOrFail();
        $date = '2026-11-14';
        DB::table('attendance_records')
            ->where('employee_id', $employee->employee_id)
            ->where('date', $date)
            ->delete();

        Carbon::setTestNow(Carbon::parse('2026-11-13 18:30:00', 'UTC'));
        Sanctum::actingAs($employee);

        $response = $this->postJson('/api/v1/attendance/punch', [
            'employee_id' => $employee->employee_id,
            'location' => 'Test location',
        ])->assertOk()
            ->assertJsonPath('type', 'in')
            ->assertJsonPath('data.date', $date)
            ->assertJsonPath('data.punch_in', '12:00 AM');

        $this->assertSame(0.0, (float) $response->json('data.work_hours'));
        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->employee_id,
            'date' => $date,
            'work_hours' => 0,
        ]);
    }

    public function test_punch_out_records_india_time_and_actual_work_hours(): void
    {
        $employee = User::where('role', 'employee')->firstOrFail();
        $date = '2026-11-14';
        DB::table('attendance_records')
            ->where('employee_id', $employee->employee_id)
            ->where('date', $date)
            ->delete();
        DB::table('attendance_records')->insert([
            'employee_id' => $employee->employee_id,
            'date' => $date,
            'punch_in' => '09:00 AM',
            'punch_out' => null,
            'status' => 'Present',
            'work_hours' => 0,
            'location' => 'Test location',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Carbon::setTestNow(Carbon::parse('2026-11-14 07:45:00', 'UTC'));
        Sanctum::actingAs($employee);

        $response = $this->postJson('/api/v1/attendance/punch', [
            'employee_id' => $employee->employee_id,
            'location' => 'Test location',
        ])->assertOk()
            ->assertJsonPath('type', 'out')
            ->assertJsonPath('data.punch_out', '01:15 PM');

        $this->assertSame(4.25, (float) $response->json('data.work_hours'));
        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->employee_id,
            'date' => $date,
            'punch_in' => '09:00 AM',
            'punch_out' => '01:15 PM',
            'work_hours' => 4.25,
        ]);
    }
}
