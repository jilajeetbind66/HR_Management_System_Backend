<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_courses', function (Blueprint $table) {
            $table->text('material_url')->nullable();
            $table->foreignId('material_upload_id')->nullable()->constrained('hrms_uploads')->nullOnDelete();
        });

        Schema::table('training_enrollments', function (Blueprint $table) {
            $table->foreignId('certificate_upload_id')->nullable()->constrained('hrms_uploads')->nullOnDelete();
        });

        Schema::create('training_notification_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('enrollment_id')->constrained('training_enrollments')->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event_key', 80);
            $table->timestamps();
            $table->unique(['enrollment_id', 'recipient_user_id', 'event_key'], 'training_notification_dispatch_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_notification_dispatches');

        Schema::table('training_enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('certificate_upload_id');
        });

        Schema::table('training_courses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_upload_id');
            $table->dropColumn('material_url');
        });
    }
};
