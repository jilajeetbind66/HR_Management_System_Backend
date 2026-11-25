<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Chat Threads (Conversations between Super Admin and Employee)
        if (!Schema::hasTable('chat_threads')) {
            Schema::create('chat_threads', function (Blueprint $table) {
                $table->id();
                $table->foreignId('admin_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
                $table->text('last_message')->nullable();
                $table->timestamp('last_message_at')->nullable();
                $table->foreignId('last_sender_id')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('unread_admin_count')->default(0);
                $table->unsignedInteger('unread_employee_count')->default(0);
                $table->timestamps();

                $table->unique(['admin_id', 'employee_id']);
                $table->index('last_message_at');
                $table->index(['admin_id', 'unread_admin_count']);
                $table->index(['employee_id', 'unread_employee_count']);
            });
        }

        // 2. Chat Messages
        if (!Schema::hasTable('chat_messages')) {
            Schema::create('chat_messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('thread_id')->constrained('chat_threads')->cascadeOnDelete();
                $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('receiver_id')->constrained('users')->cascadeOnDelete();
                $table->text('message');
                $table->boolean('is_read')->default(false);
                $table->timestamp('read_at')->nullable();
                $table->timestamps();

                $table->index(['thread_id', 'id']);
                $table->index(['receiver_id', 'is_read']);
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
        Schema::dropIfExists('chat_threads');
    }
};
