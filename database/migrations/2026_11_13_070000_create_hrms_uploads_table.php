<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrms_uploads', function (Blueprint $table) {
            $table->id();
            $table->string('module', 40);
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->unsignedBigInteger('record_id')->nullable();
            $table->foreignId('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('document_type', 100)->nullable();
            $table->string('original_filename');
            $table->string('cloudinary_public_id')->unique();
            $table->string('cloudinary_resource_type', 20);
            $table->string('cloudinary_delivery_type', 20);
            $table->string('cloudinary_format', 20)->nullable();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            $table->index(['module', 'employee_id']);
            $table->index(['module', 'record_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrms_uploads');
    }
};
