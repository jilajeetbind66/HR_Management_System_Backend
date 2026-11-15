<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Company master (Single legal entity in India)
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('legal_name');
            $table->string('cin')->nullable();
            $table->string('pan', 10);
            $table->string('tan', 10)->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('pf_code')->nullable();
            $table->string('esi_code')->nullable();
            $table->string('registered_state')->default('Uttar Pradesh');
            $table->string('currency', 3)->default('INR');
            $table->string('timezone')->default('Asia/Kolkata');
            $table->text('headquarters');
            $table->string('support_email')->nullable();
            $table->string('contact_number')->nullable();
            $table->timestamps();
        });

        // Branches
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->string('name');
            $table->string('code', 20)->unique();
            $table->string('city');
            $table->string('state');
            $table->boolean('pt_applicable')->default(true);
            $table->unsignedInteger('employees_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Departments
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->string('name');
            $table->string('code', 20);
            $table->string('head')->nullable();
            $table->decimal('budget_inr', 14, 2)->default(0);
            $table->timestamps();
        });

        // Designations
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->string('title');
            $table->string('level', 10)->default('L3');
            $table->unsignedSmallInteger('min_experience_years')->default(1);
            $table->timestamps();
        });

        // Holidays
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('date');
            $table->string('type', 30)->default('Gazetted'); // National, Gazetted, Restricted
            $table->json('branch_ids')->nullable(); // Array of branch IDs
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
        Schema::dropIfExists('designations');
        Schema::dropIfExists('departments');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('companies');
    }
};
