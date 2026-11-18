<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->boolean('is_half_day')->default(false);
            $table->string('half_day_period', 20)->nullable();
            $table->foreignId('certificate_upload_id')
                ->nullable()
                ->constrained('hrms_uploads')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropForeign(['certificate_upload_id']);
            $table->dropColumn(['is_half_day', 'half_day_period', 'certificate_upload_id']);
        });
    }
};
