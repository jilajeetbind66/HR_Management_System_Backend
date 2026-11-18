<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Add employee lead access permission flag
        if (!Schema::hasColumn('employees', 'has_lead_access')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->boolean('has_lead_access')->default(false)->after('status');
                $table->index('has_lead_access');
            });
        }

        // 2. Create leads table
        if (!Schema::hasTable('leads')) {
            Schema::create('leads', function (Blueprint $table) {
                $table->id();
                $table->string('lead_name');
                $table->date('lead_date');
                $table->string('status', 50)->default('New'); // New, In Progress, Converted, Lost
                $table->boolean('follow_up_required')->default(false);
                $table->date('follow_up_date')->nullable();
                $table->text('remarks')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index('status');
                $table->index('lead_date');
                $table->index(['follow_up_required', 'follow_up_date']);
            });
        }

        // 3. Create lead_assignments pivot table (supports 1 or multiple employees)
        if (!Schema::hasTable('lead_assignments')) {
            Schema::create('lead_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
                $table->foreignId('assigned_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique(['lead_id', 'employee_id']);
                $table->index('employee_id');
            });
        }

        // 4. Create lead_activities table for progress updates & remarks
        if (!Schema::hasTable('lead_activities')) {
            Schema::create('lead_activities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('action', 50); // created, status_changed, follow_up_updated, note_added
                $table->text('note')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['lead_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('lead_assignments');
        Schema::dropIfExists('leads');

        if (Schema::hasColumn('employees', 'has_lead_access')) {
            Schema::table('employees', function (Blueprint $table) {
                $table->dropColumn('has_lead_access');
            });
        }
    }
};
