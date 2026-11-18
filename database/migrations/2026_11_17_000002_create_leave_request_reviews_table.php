<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->text('review_remarks')->nullable();
        });

        Schema::create('leave_request_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_request_id')->constrained('leave_requests')->cascadeOnDelete();
            $table->foreignId('reviewer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reviewer_name');
            $table->string('stage', 60);
            $table->string('decision', 20);
            $table->text('remarks')->nullable();
            $table->timestamp('reviewed_at');
            $table->timestamps();
            $table->index(['leave_request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_request_reviews');
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('review_remarks');
        });
    }
};
