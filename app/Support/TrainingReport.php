<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class TrainingReport
{
    public static function branchCompliance(?array $employeeIds = null)
    {
        return DB::table('training_enrollments as enrollment')
            ->join('training_courses as course', 'course.id', '=', 'enrollment.course_id')
            ->join('employees', 'employees.id', '=', 'enrollment.employee_id')
            ->leftJoin('branches', 'branches.id', '=', 'employees.branch_id')
            ->where('course.status', 'active')
            ->where('course.mandatory', true)
            ->where('employees.is_demo', false)
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employees.id', $employeeIds))
            ->selectRaw("COALESCE(branches.id, 0) as branch_id, COALESCE(branches.name, 'Unassigned') as branch_name")
            ->selectRaw('COUNT(*) as assigned_count')
            ->selectRaw("SUM(CASE WHEN enrollment.status = 'completed' THEN 1 ELSE 0 END) as completed_count")
            ->selectRaw("SUM(CASE WHEN enrollment.status = 'pending_review' THEN 1 ELSE 0 END) as pending_review_count")
            ->selectRaw(
                "SUM(CASE WHEN enrollment.status <> 'completed' AND course.due_date IS NOT NULL AND course.due_date < ? THEN 1 ELSE 0 END) as overdue_count",
                [Carbon::today()->toDateString()]
            )
            ->groupBy('branches.id', 'branches.name')
            ->orderBy('branch_name')
            ->get()
            ->map(function ($branch) {
                $branch->compliance_percent = (int) $branch->assigned_count > 0
                    ? round((int) $branch->completed_count * 100 / (int) $branch->assigned_count, 1)
                    : 0;

                return $branch;
            });
    }
}
