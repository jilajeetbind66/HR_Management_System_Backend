<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Identify all employee IDs linked to super_admin users
        $superAdminUsers = DB::table('users')->where('role', 'super_admin')->get();
        $linkedEmployeeIds = $superAdminUsers->pluck('employee_id')->filter()->unique()->values()->all();

        if (!empty($linkedEmployeeIds)) {
            // 2. Direct reports assigned to Super Admin are re-assigned to Direct to Board (null)
            DB::table('employees')
                ->whereIn('reporting_manager_id', $linkedEmployeeIds)
                ->update(['reporting_manager_id' => null]);

            // 3. Clean up statutory, leave balances, and dummy payroll for super admins
            DB::table('employee_statutory_details')->whereIn('employee_id', $linkedEmployeeIds)->delete();
            DB::table('leave_balances')->whereIn('employee_id', $linkedEmployeeIds)->delete();
            DB::table('payslips')->whereIn('employee_id', $linkedEmployeeIds)->delete();
            DB::table('attendance_records')->whereIn('employee_id', $linkedEmployeeIds)->delete();

            // 4. Mark those employee records as soft-deleted / inactive so they disappear from employee directory
            DB::table('employees')
                ->whereIn('id', $linkedEmployeeIds)
                ->update([
                    'status' => 'inactive',
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        // 5. Unlink all super_admin accounts from employees table
        DB::table('users')
            ->where('role', 'super_admin')
            ->update([
                'employee_id' => null,
                'updated_at' => now(),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No-op rollback
    }
};
