<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
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
            'status' => 'Present',
        ]);
    }

    public function test_completed_work_below_the_configured_minimum_is_not_present(): void
    {
        $employee = User::where('role', 'employee')->firstOrFail();
        $date = '2026-11-14';
        DB::table('attendance_records')->where('employee_id', $employee->employee_id)
            ->where('date', $date)->delete();
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
        Config::set('hrms.minimum_work_hours', 4);
        Config::set('hrms.below_minimum_attendance_status', 'Short Hours');
        Carbon::setTestNow(Carbon::parse('2026-11-14 03:31:00', 'UTC'));
        Sanctum::actingAs($employee);

        $response = $this->postJson('/api/v1/attendance/punch', [
            'employee_id' => $employee->employee_id,
            'location' => 'Test location',
        ])->assertOk()->assertJsonPath('data.status', 'Short Hours');

        $this->assertSame(0.02, (float) $response->json('data.work_hours'));
        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->employee_id,
            'date' => $date,
            'work_hours' => 0.02,
            'status' => 'Short Hours',
        ]);
    }

    public function test_approved_regularization_uses_the_same_minimum_hours_status(): void
    {
        $employee = User::where('role', 'employee')->firstOrFail();
        $manager = User::where('role', 'manager')->firstOrFail();
        DB::table('employees')->where('id', $employee->employee_id)->update([
            'reporting_manager_id' => $manager->employee_id,
        ]);
        Config::set('hrms.minimum_work_hours', 4);
        Config::set('hrms.below_minimum_attendance_status', 'Half Day');

        Sanctum::actingAs($employee);
        $request = $this->postJson('/api/v1/attendance/regularizations', [
            'date' => '2026-11-18',
            'punch_in_requested' => '09:00 AM',
            'punch_out_requested' => '09:01 AM',
            'reason' => 'Correct a missed short shift punch.',
        ])->assertCreated();

        Sanctum::actingAs($manager);
        $this->postJson("/api/v1/attendance/regularizations/{$request->json('data.id')}/review", [
            'status' => 'approved',
        ])->assertOk();

        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->employee_id,
            'date' => '2026-11-18',
            'work_hours' => 0.02,
            'status' => 'Half Day',
        ]);
    }

    public function test_completed_daily_punch_session_cannot_be_started_again(): void
    {
        $employee = User::where('role', 'employee')->firstOrFail();
        $date = '2026-11-14';
        DB::table('attendance_records')->where('employee_id', $employee->employee_id)
            ->where('date', $date)->delete();
        DB::table('attendance_records')->insert([
            'employee_id' => $employee->employee_id,
            'date' => $date,
            'punch_in' => '09:00 AM',
            'punch_out' => '10:00 AM',
            'status' => 'Short Hours',
            'work_hours' => 1,
            'location' => 'Test location',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Carbon::setTestNow(Carbon::parse('2026-11-14 05:30:00', 'UTC'));
        Sanctum::actingAs($employee);

        $this->postJson('/api/v1/attendance/punch', [
            'employee_id' => $employee->employee_id,
            'location' => 'Second session attempt',
        ])->assertOk()
            ->assertJsonPath('type', 'already_completed')
            ->assertJsonPath('data.punch_out', '10:00 AM')
            ->assertJsonPath('data.work_hours', 1);

        $this->assertSame(1, DB::table('attendance_records')
            ->where('employee_id', $employee->employee_id)
            ->where('date', $date)
            ->count());
    }
}
