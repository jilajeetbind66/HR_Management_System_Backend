<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('state');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->unsignedInteger('radius_meters')->default(150)->after('longitude');
            $table->boolean('geofence_enabled')->default(true)->after('radius_meters');
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('ip_address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
            $table->decimal('distance_meters', 8, 2)->nullable()->after('longitude');
            $table->string('geofence_status', 30)->default('verified')->after('distance_meters');
        });
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'radius_meters', 'geofence_enabled']);
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude', 'distance_meters', 'geofence_status']);
        });
    }
};
