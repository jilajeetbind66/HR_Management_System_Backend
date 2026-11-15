<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->timestamp('resolved_at')->nullable()->after('sla_due_at');
            $table->timestamp('closed_at')->nullable()->after('resolved_at');
        });

        Schema::create('helpdesk_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('helpdesk_tickets')->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by');
            $table->string('type', 20)->default('comment');
            $table->text('body');
            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_ticket_messages');

        Schema::table('helpdesk_tickets', function (Blueprint $table) {
            $table->dropColumn(['description', 'resolved_at', 'closed_at']);
        });
    }
};
