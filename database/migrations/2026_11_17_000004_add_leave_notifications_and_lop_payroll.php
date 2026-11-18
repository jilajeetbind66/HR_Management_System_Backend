<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('lop_days', 5, 2)->default(0);
            $table->decimal('lop_deduction', 14, 2)->default(0);
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->json('counted_dates')->nullable();
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('event_key', 80);
            $table->string('title', 160);
            $table->text('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notifications');

        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn(['lop_days', 'lop_deduction']);
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('counted_dates');
        });
    }
};
