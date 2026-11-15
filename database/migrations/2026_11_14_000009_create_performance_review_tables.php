<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_cycles', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('quarter');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['year', 'quarter']);
        });

        Schema::create('performance_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cycle_id')->constrained('performance_cycles')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('weight_percent', 5, 2);
            $table->string('status', 20)->default('draft');
            $table->timestamp('submitted_at')->nullable();
            $table->text('manager_note')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedTinyInteger('self_rating')->nullable();
            $table->text('self_review_note')->nullable();
            $table->timestamp('self_reviewed_at')->nullable();
            $table->unsignedTinyInteger('manager_rating')->nullable();
            $table->text('manager_review_note')->nullable();
            $table->foreignId('manager_rated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('manager_rated_at')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'cycle_id']);
            $table->index(['cycle_id', 'status']);
        });

        Schema::create('performance_key_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('objective_id')->constrained('performance_objectives')->cascadeOnDelete();
            $table->string('title');
            $table->unsignedTinyInteger('progress_percent')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_key_results');
        Schema::dropIfExists('performance_objectives');
        Schema::dropIfExists('performance_cycles');
    }
};
