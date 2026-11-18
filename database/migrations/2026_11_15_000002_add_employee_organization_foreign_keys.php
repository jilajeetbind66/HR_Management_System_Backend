<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('department_id')
                ->nullable()
                ->index()
                ->constrained('departments')
                ->nullOnDelete();
            $table->foreignId('designation_id')
                ->nullable()
                ->index()
                ->constrained('designations')
                ->nullOnDelete();
        });

        $employees = DB::table('employees')
            ->select('id', 'department', 'designation', 'branch_id')
            ->get();

        foreach ($employees as $employee) {
            $departments = DB::table('departments')
                ->whereRaw('LOWER(TRIM(name)) = ?', [strtolower(trim($employee->department))])
                ->get(['id', 'branch_id']);
            $branchMatches = $departments->where('branch_id', $employee->branch_id);
            $department = $branchMatches->count() === 1
                ? $branchMatches->first()
                : ($departments->count() === 1 ? $departments->first() : null);

            $designationId = null;
            if ($department) {
                $designations = DB::table('designations')
                    ->where('department_id', $department->id)
                    ->whereRaw('LOWER(TRIM(title)) = ?', [strtolower(trim($employee->designation))])
                    ->limit(2)
                    ->get(['id']);
                if ($designations->count() === 1) {
                    $designationId = $designations->first()->id;
                }
            }

            DB::table('employees')->where('id', $employee->id)->update([
                'department_id' => $department?->id,
                'designation_id' => $designationId,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('designation_id');
            $table->dropConstrainedForeignId('department_id');
        });
    }
};
