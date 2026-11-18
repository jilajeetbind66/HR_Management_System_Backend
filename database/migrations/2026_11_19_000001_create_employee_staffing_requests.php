<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_staffing_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manager_employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('request_type', 20);
            $table->string('designation');
            $table->string('department');
            $table->string('requested_employee_code', 20)->nullable();
            $table->text('justification');
            $table->string('status', 20)->default('pending');
            $table->foreignId('assigned_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'request_type']);
            $table->index(['manager_employee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_staffing_requests');
    }
};
