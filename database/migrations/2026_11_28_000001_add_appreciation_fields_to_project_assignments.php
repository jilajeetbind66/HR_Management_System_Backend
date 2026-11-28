<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_assignments', function (Blueprint $table) {
            if (!Schema::hasColumn('project_assignments', 'delivery_timeliness')) {
                $table->string('delivery_timeliness', 32)->nullable()->after('actual_completed_at');
            }
            if (!Schema::hasColumn('project_assignments', 'days_ahead_or_late')) {
                $table->integer('days_ahead_or_late')->nullable()->after('delivery_timeliness');
            }
            if (!Schema::hasColumn('project_assignments', 'appreciation_rating')) {
                $table->unsignedTinyInteger('appreciation_rating')->nullable()->after('days_ahead_or_late');
            }
            if (!Schema::hasColumn('project_assignments', 'appreciation_badge')) {
                $table->string('appreciation_badge', 64)->nullable()->after('appreciation_rating');
            }
            if (!Schema::hasColumn('project_assignments', 'appreciation_note')) {
                $table->text('appreciation_note')->nullable()->after('appreciation_badge');
            }
            if (!Schema::hasColumn('project_assignments', 'appreciated_by')) {
                $table->string('appreciated_by', 120)->nullable()->after('appreciation_note');
            }
            if (!Schema::hasColumn('project_assignments', 'appreciated_at')) {
                $table->timestamp('appreciated_at')->nullable()->after('appreciated_by');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_assignments', function (Blueprint $table) {
            $columns = [
                'delivery_timeliness',
                'days_ahead_or_late',
                'appreciation_rating',
                'appreciation_badge',
                'appreciation_note',
                'appreciated_by',
                'appreciated_at',
            ];
            $existing = array_filter($columns, fn ($col) => Schema::hasColumn('project_assignments', $col));
            if (!empty($existing)) {
                $table->dropColumn($existing);
            }
        });
    }
};
