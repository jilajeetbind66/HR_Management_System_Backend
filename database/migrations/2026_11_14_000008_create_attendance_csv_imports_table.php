<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_csv_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_id')->unique()->constrained('hrms_uploads')->cascadeOnDelete();
            $table->foreignId('processed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('previewed');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('attendance_day_count')->default(0);
            $table->unsignedInteger('imported_count')->default(0);
            $table->unsignedInteger('existing_count')->default(0);
            $table->unsignedInteger('duplicate_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('summary')->nullable();
            $table->timestamp('previewed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'processed_at']);
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->foreignId('attendance_csv_import_id')
                ->nullable()
                ->constrained('attendance_csv_imports')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropForeign(['attendance_csv_import_id']);
            $table->dropColumn('attendance_csv_import_id');
        });

        Schema::dropIfExists('attendance_csv_imports');
    }
};
