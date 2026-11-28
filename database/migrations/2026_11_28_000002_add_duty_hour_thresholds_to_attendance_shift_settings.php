<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attendance_shift_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_shift_settings', 'full_day_min_hours')) {
                $table->decimal('full_day_min_hours', 4, 2)->nullable()->after('grace_period_minutes');
            }
            if (!Schema::hasColumn('attendance_shift_settings', 'half_day_min_hours')) {
                $table->decimal('half_day_min_hours', 4, 2)->nullable()->after('full_day_min_hours');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_shift_settings', function (Blueprint $table) {
            $cols = array_filter(
                ['full_day_min_hours', 'half_day_min_hours'],
                fn ($c) => Schema::hasColumn('attendance_shift_settings', $c)
            );
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
