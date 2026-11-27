<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            if (!Schema::hasColumn('projects', 'is_weekend_emergency')) {
                $table->boolean('is_weekend_emergency')->default(false)->after('project_type');
            }
            if (!Schema::hasColumn('projects', 'emergency_reason')) {
                $table->text('emergency_reason')->nullable()->after('is_weekend_emergency');
            }
            if (!Schema::hasColumn('projects', 'weekend_benefit_type')) {
                $table->string('weekend_benefit_type', 50)->nullable()->after('emergency_reason');
            }
            if (!Schema::hasColumn('projects', 'weekend_benefit_amount')) {
                $table->decimal('weekend_benefit_amount', 10, 2)->nullable()->after('weekend_benefit_type');
            }
            if (!Schema::hasColumn('projects', 'benefit_credited_at')) {
                $table->timestamp('benefit_credited_at')->nullable()->after('weekend_benefit_amount');
            }
        });

        Schema::table('project_assignments', function (Blueprint $table) {
            if (!Schema::hasColumn('project_assignments', 'is_weekend_emergency')) {
                $table->boolean('is_weekend_emergency')->default(false)->after('assigned_by_role');
            }
            if (!Schema::hasColumn('project_assignments', 'weekend_benefit_type')) {
                $table->string('weekend_benefit_type', 50)->nullable()->after('is_weekend_emergency');
            }
            if (!Schema::hasColumn('project_assignments', 'weekend_benefit_amount')) {
                $table->decimal('weekend_benefit_amount', 10, 2)->nullable()->after('weekend_benefit_type');
            }
            if (!Schema::hasColumn('project_assignments', 'benefit_credited_at')) {
                $table->timestamp('benefit_credited_at')->nullable()->after('weekend_benefit_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'is_weekend_emergency',
                'emergency_reason',
                'weekend_benefit_type',
                'weekend_benefit_amount',
                'benefit_credited_at',
            ]);
        });

        Schema::table('project_assignments', function (Blueprint $table) {
            $table->dropColumn([
                'is_weekend_emergency',
                'weekend_benefit_type',
                'weekend_benefit_amount',
                'benefit_credited_at',
            ]);
        });
    }
};
