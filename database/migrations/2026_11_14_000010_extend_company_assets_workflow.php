<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_assets', function (Blueprint $table) {
            $table->foreignId('assigned_employee_id')
                ->nullable()
                ->after('assigned_to')
                ->constrained('employees')
                ->nullOnDelete();
            $table->foreignId('branch_id')
                ->nullable()
                ->after('branch')
                ->constrained('branches')
                ->nullOnDelete();
        });

        DB::table('company_assets')->orderBy('id')->get(['id', 'branch'])->each(function ($asset) {
            $branchId = DB::table('branches')->where('name', $asset->branch)->value('id');
            if ($branchId) {
                DB::table('company_assets')->where('id', $asset->id)->update(['branch_id' => $branchId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('company_assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_employee_id');
            $table->dropConstrainedForeignId('branch_id');
        });
    }
};
