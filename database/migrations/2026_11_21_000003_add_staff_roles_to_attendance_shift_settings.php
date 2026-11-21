<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_shift_settings', function (Blueprint $table) {
            $table->boolean('staff_shift_enabled')->default(true)->after('auto_half_day_deduction');
            $table->string('staff_shift_name', 100)->default('Management & Staff Shift')->after('staff_shift_enabled');
            $table->string('staff_start_time', 20)->default('10:00 AM')->after('staff_shift_name');
            $table->string('staff_end_time', 20)->default('07:00 PM')->after('staff_start_time');
            $table->unsignedInteger('staff_grace_period_minutes')->default(15)->after('staff_end_time');
            $table->json('staff_applied_roles')->nullable()->after('staff_grace_period_minutes');
        });

        // Initialize default roles for staff shift
        $defaultRoles = json_encode(['manager', 'hr_admin', 'hr_executive', 'recruiter', 'payroll_admin', 'auditor']);
        DB::table('attendance_shift_settings')->update([
            'staff_shift_enabled' => true,
            'staff_shift_name' => 'Management & Staff Shift',
            'staff_start_time' => '10:00 AM',
            'staff_end_time' => '07:00 PM',
            'staff_grace_period_minutes' => 15,
            'staff_applied_roles' => $defaultRoles,
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('attendance_shift_settings', function (Blueprint $table) {
            $table->dropColumn([
                'staff_shift_enabled',
                'staff_shift_name',
                'staff_start_time',
                'staff_end_time',
                'staff_grace_period_minutes',
                'staff_applied_roles',
            ]);
        });
    }
};
