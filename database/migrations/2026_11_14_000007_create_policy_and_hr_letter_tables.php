<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hrms_uploads', function (Blueprint $table) {
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
        });

        Schema::create('company_policies', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('category', 50);
            $table->string('version', 20);
            $table->date('effective_date');
            $table->boolean('mandatory')->default(true);
            $table->text('content');
            $table->string('status', 20)->default('active');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['title', 'version']);
            $table->index(['status', 'effective_date']);
        });

        Schema::create('policy_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_policy_id')->constrained('company_policies')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('policy_version', 20);
            $table->foreignId('acknowledged_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at');
            $table->timestamps();

            $table->unique(['company_policy_id', 'employee_id']);
            $table->index(['employee_id', 'acknowledged_at']);
        });

        Schema::create('hr_letters', function (Blueprint $table) {
            $table->id();
            $table->string('letter_number', 30)->unique();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('letter_type', 40);
            $table->string('status', 20)->default('draft');
            $table->json('snapshot');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'letter_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_letters');
        Schema::dropIfExists('policy_acknowledgements');
        Schema::dropIfExists('company_policies');

        Schema::table('hrms_uploads', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by_user_id']);
            $table->dropColumn(['reviewed_by_user_id', 'reviewed_at', 'review_note']);
        });
    }
};
