<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceCsvImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
        Config::set('services.cloudinary.cloud_name', 'attendance-test-cloud');
        Config::set('services.cloudinary.api_key', 'test-api-key');
        Config::set('services.cloudinary.api_secret', 'test-api-secret');
    }

    public function test_preview_and_processing_group_punches_and_are_idempotent(): void
    {
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        $firstEmployee = DB::table('employees')->where('employee_code', 'EMP-005')->firstOrFail();
        $secondEmployee = DB::table('employees')->where('employee_code', 'EMP-006')->firstOrFail();
        $date = now()->addDay()->toDateString();
        $uploadId = $this->createCsvUpload($hrAdmin);
        Http::fake(['*' => Http::response(implode("\n", [
            'employee_code,punch_time,device_id,location_code',
            "EMP-005,{$date} 09:00:00,DEV-1,PRG",
            "EMP-005,{$date} 18:30:00,DEV-1,PRG",
            "EMP-005,{$date} 09:00:00,DEV-1,PRG",
            "EMP-006,{$date}T03:30:00Z,DEV-2,KSM",
        ]), 200)]);
        Sanctum::actingAs($hrAdmin);

        $this->postJson("/api/v1/attendance/imports/{$uploadId}/preview")
            ->assertOk()
            ->assertJsonPath('data.status', 'previewed')
            ->assertJsonPath('data.row_count', 4)
            ->assertJsonPath('data.attendance_day_count', 2)
            ->assertJsonPath('data.new_record_count', 2)
            ->assertJsonPath('data.duplicate_count', 1)
            ->assertJsonPath('data.error_count', 0)
            ->assertJsonPath('data.can_process', true);

        $processed = $this->postJson("/api/v1/attendance/imports/{$uploadId}/process")
            ->assertOk()
            ->assertJsonPath('data.status', 'processed')
            ->assertJsonPath('data.imported_count', 2)
            ->assertJsonPath('data.existing_count', 0)
            ->json('data');

        $importId = DB::table('attendance_csv_imports')->where('upload_id', $uploadId)->value('id');
        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $firstEmployee->id,
            'attendance_csv_import_id' => $importId,
            'date' => $date,
            'punch_in' => '09:00 AM',
            'punch_out' => '06:30 PM',
            'work_hours' => 9.5,
            'location' => 'Biometric: PRG / Device DEV-1',
        ]);
        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $secondEmployee->id,
            'date' => $date,
            'punch_in' => '09:00 AM',
            'punch_out' => null,
            'work_hours' => 0,
        ]);

        $this->postJson("/api/v1/attendance/imports/{$uploadId}/process")
            ->assertOk()
            ->assertJsonPath('data.imported_count', 2);
        $this->assertSame(2, DB::table('attendance_records')->where('date', $date)->count());
        $this->getJson('/api/v1/attendance/imports')
            ->assertOk()
            ->assertJsonPath('data.0.import_status', 'processed')
            ->assertJsonPath('data.0.summary.imported_count', 2);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'ATTENDANCE_CSV_PROCESSED',
            'module' => 'Attendance',
        ]);
    }

    public function test_existing_attendance_is_never_overwritten_by_import(): void
    {
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        $employee = DB::table('employees')->where('employee_code', 'EMP-005')->firstOrFail();
        $date = now()->addDay()->toDateString();
        DB::table('attendance_records')->insert([
            'employee_id' => $employee->id,
            'date' => $date,
            'punch_in' => '08:55 AM',
            'punch_out' => '05:05 PM',
            'status' => 'Present',
            'work_hours' => 8.17,
            'location' => 'Web Portal',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $uploadId = $this->createCsvUpload($hrAdmin);
        Http::fake(['*' => Http::response(implode("\n", [
            'employee_code,punch_time',
            "EMP-005,{$date} 09:00:00",
            "EMP-005,{$date} 18:30:00",
        ]), 200)]);
        Sanctum::actingAs($hrAdmin);

        $this->postJson("/api/v1/attendance/imports/{$uploadId}/preview")
            ->assertOk()
            ->assertJsonPath('data.existing_count', 1)
            ->assertJsonPath('data.new_record_count', 0);
        $this->postJson("/api/v1/attendance/imports/{$uploadId}/process")
            ->assertOk()
            ->assertJsonPath('data.imported_count', 0)
            ->assertJsonPath('data.existing_count', 1);
        $this->assertDatabaseHas('attendance_records', [
            'employee_id' => $employee->id,
            'date' => $date,
            'punch_in' => '08:55 AM',
            'punch_out' => '05:05 PM',
            'location' => 'Web Portal',
            'attendance_csv_import_id' => null,
        ]);
    }

    public function test_invalid_csv_rows_block_the_entire_import(): void
    {
        $hrAdmin = User::where('role', 'hr_admin')->firstOrFail();
        $date = now()->addDay()->toDateString();
        $uploadId = $this->createCsvUpload($hrAdmin);
        Http::fake(['*' => Http::response(implode("\n", [
            'employee_code,punch_time',
            "EMP-005,{$date} 09:00:00",
            "EMP-999,{$date} 18:30:00",
            'EMP-006,not-a-date',
            'EMP-006,2026-02-31 11:00:00',
        ]), 200)]);
        Sanctum::actingAs($hrAdmin);

        $this->postJson("/api/v1/attendance/imports/{$uploadId}/preview")
            ->assertOk()
            ->assertJsonPath('data.status', 'invalid')
            ->assertJsonPath('data.error_count', 3)
            ->assertJsonPath('data.can_process', false);
        $this->postJson("/api/v1/attendance/imports/{$uploadId}/process")
            ->assertUnprocessable()
            ->assertJsonPath('data.error_count', 3);
        $this->assertDatabaseMissing('attendance_records', [
            'employee_id' => DB::table('employees')->where('employee_code', 'EMP-005')->value('id'),
            'date' => $date,
        ]);
        $this->assertDatabaseHas('attendance_csv_imports', [
            'upload_id' => $uploadId,
            'status' => 'invalid',
            'error_count' => 3,
        ]);
    }

    public function test_import_access_is_limited_and_hr_executive_is_read_only(): void
    {
        $manager = User::where('role', 'manager')->firstOrFail();
        $hrExecutive = User::factory()->create([
            'name' => 'Attendance HR Executive',
            'email' => 'attendance.executive@example.com',
            'role' => 'hr_executive',
        ]);
        $uploadId = $this->createCsvUpload(User::where('role', 'hr_admin')->firstOrFail());

        Sanctum::actingAs($manager);
        $this->getJson('/api/v1/attendance/imports')->assertForbidden();
        $this->getJson('/api/v1/uploads?module=attendance_csv')->assertForbidden();
        $this->post('/api/v1/uploads', [
            'module' => 'attendance_csv',
            'file' => UploadedFile::fake()->create('manager.csv', 1, 'text/csv'),
        ])->assertForbidden();

        Sanctum::actingAs($hrExecutive);
        $this->getJson('/api/v1/attendance/imports')->assertOk();
        $this->postJson("/api/v1/attendance/imports/{$uploadId}/preview")->assertForbidden();
        $this->postJson("/api/v1/attendance/imports/{$uploadId}/process")->assertForbidden();
        $this->post('/api/v1/uploads', [
            'module' => 'attendance_csv',
            'file' => UploadedFile::fake()->create('executive.csv', 1, 'text/csv'),
        ])->assertForbidden();
    }

    private function createCsvUpload(User $uploader): int
    {
        return DB::table('hrms_uploads')->insertGetId([
            'module' => 'attendance_csv',
            'uploaded_by_user_id' => $uploader->id,
            'original_filename' => 'biometric-attendance.csv',
            'cloudinary_public_id' => (string) Str::uuid(),
            'cloudinary_resource_type' => 'raw',
            'cloudinary_delivery_type' => 'authenticated',
            'cloudinary_format' => 'csv',
            'mime_type' => 'text/csv',
            'file_size' => 1024,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
