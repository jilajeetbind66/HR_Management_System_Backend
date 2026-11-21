<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_shift_settings', function (Blueprint $table) {
            $table->id();
            $table->string('shift_name')->default('General Day Shift');
            $table->string('start_time', 20)->default('09:30 AM');
            $table->string('end_time', 20)->default('06:30 PM');
            $table->unsignedInteger('grace_period_minutes')->default(15);
            $table->unsignedInteger('late_mark_threshold')->default(3);
            $table->boolean('auto_half_day_deduction')->default(true);
            $table->timestamps();
        });

        DB::table('attendance_shift_settings')->insert([
            'shift_name' => 'General Day Shift',
            'start_time' => '09:30 AM',
            'end_time' => '06:30 PM',
            'grace_period_minutes' => 15,
            'late_mark_threshold' => 3,
            'auto_half_day_deduction' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_shift_settings');
    }
};
