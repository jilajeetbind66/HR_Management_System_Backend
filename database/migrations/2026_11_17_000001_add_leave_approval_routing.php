<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->string('approval_stage', 60)->nullable();
            $table->string('next_approver_role', 50)->nullable();
        });

        foreach (DB::table('leave_requests')->where('status', 'pending')->get(['id', 'employee_id']) as $leave) {
            $applicantRole = DB::table('users')
                ->where('employee_id', $leave->employee_id)
                ->value('role') ?? 'employee';
            $managerId = DB::table('employees')
                ->where('id', $leave->employee_id)
                ->value('reporting_manager_id');

            if ($applicantRole === 'super_admin') {
                $stage = 'Super Admin / HR Head Approval';
                $nextApproverRole = 'super_admin_or_hr_head';
            } elseif (in_array($applicantRole, ['hr_admin', 'hr_executive'], true)) {
                $stage = 'Super Admin Approval';
                $nextApproverRole = 'super_admin';
            } elseif ($applicantRole === 'manager') {
                $stage = 'HR Approval';
                $nextApproverRole = 'hr';
            } elseif ($managerId) {
                $stage = 'Manager Approval';
                $nextApproverRole = 'manager';
            } else {
                $stage = 'No manager assigned';
                $nextApproverRole = 'hr';
            }

            DB::table('leave_requests')->where('id', $leave->id)->update([
                'approval_stage' => $stage,
                'next_approver_role' => $nextApproverRole,
            ]);
        }

        DB::table('leave_requests')->where('status', 'approved')->update(['approval_stage' => 'Approved']);
        DB::table('leave_requests')->where('status', 'rejected')->update(['approval_stage' => 'Rejected']);
        DB::table('leave_requests')->where('status', 'cancelled')->update(['approval_stage' => 'Cancelled']);
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['approval_stage', 'next_approver_role']);
        });
    }
};
