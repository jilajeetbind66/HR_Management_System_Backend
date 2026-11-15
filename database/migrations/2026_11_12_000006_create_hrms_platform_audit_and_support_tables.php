<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Notification Preferences (Section 7.5 & 10)
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event_key');
            $table->boolean('mail_enabled')->default(true);
            $table->boolean('in_app_enabled')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'event_key']);
        });

        // Scheduler Runs (Section 7.5 & 12)
        Schema::create('scheduler_runs', function (Blueprint $table) {
            $table->id();
            $table->string('command');
            $table->string('status', 20)->default('success'); // success, failed
            $table->text('output')->nullable();
            $table->decimal('duration_ms', 10, 2)->default(0);
            $table->timestamp('ran_at');
            $table->timestamps();
        });

        // Approval Request Steps (Section 7.5 & 10 generic approval engine)
        Schema::create('approval_request_steps', function (Blueprint $table) {
            $table->id();
            $table->string('approvable_type'); // Morph model
            $table->unsignedBigInteger('approvable_id');
            $table->unsignedTinyInteger('step_order')->default(1);
            $table->string('approver_role');
            $table->foreignId('approver_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('pending'); // pending, approved, rejected, returned
            $table->text('decision_note')->nullable();
            $table->timestamp('acted_at')->nullable();
            $table->timestamps();
        });

        // Spatie-equivalent Audit Activity Logs (Section 3 & 4)
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 50);
            $table->string('actor');
            $table->string('module', 50);
            $table->text('description');
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });

        // Helpdesk Support Tickets (M14)
        Schema::create('helpdesk_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_code', 20)->unique();
            $table->string('title');
            $table->string('category', 50);
            $table->string('priority', 20)->default('Medium');
            $table->string('created_by');
            $table->string('assigned_to')->nullable();
            $table->string('status', 20)->default('Open');
            $table->unsignedSmallInteger('sla_hours_left')->default(24);
            $table->timestamps();
        });

        // Corporate Announcements (M15)
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content');
            $table->string('category', 50)->default('General');
            $table->string('target')->default('All Company');
            $table->string('published_by');
            $table->unsignedInteger('read_count')->default(0);
            $table->timestamps();
        });

        // Company Assets (M11)
        Schema::create('company_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code', 30)->unique();
            $table->string('name');
            $table->string('category', 50);
            $table->string('serial_number')->unique();
            $table->string('assigned_to')->nullable();
            $table->string('branch')->default('Prayagraj Branch');
            $table->string('status', 20)->default('Allocated');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_assets');
        Schema::dropIfExists('announcements');
        Schema::dropIfExists('helpdesk_tickets');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('approval_request_steps');
        Schema::dropIfExists('scheduler_runs');
        Schema::dropIfExists('notification_preferences');
    }
};
