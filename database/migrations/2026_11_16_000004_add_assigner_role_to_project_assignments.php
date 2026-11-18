<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_assignments', function (Blueprint $table) {
            $table->string('assigned_by_role', 30)->nullable();
        });

        DB::table('project_assignments')
            ->select(['id', 'assigned_by'])
            ->orderBy('id')
            ->chunkById(500, function ($assignments): void {
                foreach ($assignments as $assignment) {
                    $role = DB::table('users')
                        ->where('name', $assignment->assigned_by)
                        ->value('role');
                    if ($role) {
                        DB::table('project_assignments')
                            ->where('id', $assignment->id)
                            ->update(['assigned_by_role' => $role]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('project_assignments', function (Blueprint $table) {
            $table->dropColumn('assigned_by_role');
        });
    }
};
