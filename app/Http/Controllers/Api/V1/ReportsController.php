<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\RoleAccess;
use App\Support\TrainingReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportsController extends Controller
{
    private const WORKFORCE_ROLES = ['super_admin', 'manager', 'auditor'];

    private const PROJECT_ROLES = ['super_admin', 'manager'];

    private const PAYROLL_ROLES = ['super_admin', 'payroll_admin', 'auditor'];

    private const PAYROLL_EXPORT_ROLES = ['super_admin', 'payroll_admin'];

    private const TRAINING_REPORT_ROLES = ['super_admin', 'hr_admin', 'hr_executive', 'manager'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $user->role;
        $workforceEmployeeIds = $role === 'manager'
            ? RoleAccess::visibleEmployeeIds($user, false)
            : null;
        $projectEmployeeIds = $role === 'manager'
            ? RoleAccess::visibleEmployeeIds($user)
            : null;
        $canViewPayroll = in_array($role, self::PAYROLL_ROLES, true);

        $lockedRuns = $canViewPayroll
            ? DB::table('payroll_runs')
                ->whereIn('status', ['published', 'locked'])
                ->where('scope_key', '<>', 'demo')
                ->orderByDesc('period_year')
                ->orderByDesc('period_month')
                ->orderByDesc('id')
                ->limit(24)
                ->get([
                    'id',
                    'period_year',
                    'period_month',
                    'month_name',
                    'scope_key',
                    'total_employees',
                    'gross_payout_inr',
                    'total_deductions_inr',
                    'net_payout_inr',
                    'approved_at',
                    'payout_date',
                ])
            : collect();

        return response()->json([
            'data' => [
                'workforce' => in_array($role, self::WORKFORCE_ROLES, true)
                    ? $this->workforceSummary($workforceEmployeeIds)
                    : null,
                'projects' => in_array($role, self::PROJECT_ROLES, true)
                    ? $this->projectSummary($projectEmployeeIds)
                    : null,
                'payroll' => $canViewPayroll
                    ? [
                        'locked_runs' => $lockedRuns,
                        'latest_locked_run' => $lockedRuns->first(),
                    ]
                    : null,
                'training' => in_array($role, self::TRAINING_REPORT_ROLES, true)
                    ? ['branch_compliance' => TrainingReport::branchCompliance($role === 'manager' ? $workforceEmployeeIds : null)]
                    : null,
                'exports' => [
                    'employee_master' => in_array($role, ['super_admin', 'manager', 'auditor'], true),
                    'training_compliance' => in_array($role, self::TRAINING_REPORT_ROLES, true),
                    'pf_source_register' => in_array($role, self::PAYROLL_EXPORT_ROLES, true),
                    'pt_source_register' => in_array($role, self::PAYROLL_EXPORT_ROLES, true),
                    'tds_source_register' => in_array($role, self::PAYROLL_EXPORT_ROLES, true),
                ],
            ],
        ]);
    }

    public function export(Request $request, string $report): JsonResponse
    {
        $user = $request->user();

        if ($report === 'employee_master') {
            abort_unless(
                in_array($user->role, ['super_admin', 'manager', 'auditor'], true),
                403
            );

            $employeeIds = $user->role === 'manager'
                ? RoleAccess::visibleEmployeeIds($user, false)
                : null;

            return response()->json([
                'data' => [
                    'report' => $report,
                    'rows' => $this->employeeMasterRows($employeeIds),
                ],
            ]);
        }

        if ($report === 'training_compliance') {
            abort_unless(in_array($user->role, self::TRAINING_REPORT_ROLES, true), 403);
            $employeeIds = $user->role === 'manager'
                ? RoleAccess::visibleEmployeeIds($user, false)
                : null;

            return response()->json([
                'data' => [
                    'report' => $report,
                    'rows' => TrainingReport::branchCompliance($employeeIds),
                ],
            ]);
        }

        abort_unless(in_array($report, [
            'pf_source_register',
            'pt_source_register',
            'tds_source_register',
        ], true), 404);
        abort_unless(in_array($user->role, self::PAYROLL_EXPORT_ROLES, true), 403);

        $validated = $request->validate([
            'run_id' => 'required|integer|min:1',
        ]);
        $run = DB::table('payroll_runs')
            ->where('id', $validated['run_id'])
            ->whereIn('status', ['published', 'locked'])
            ->where('scope_key', '<>', 'demo')
            ->first([
                'id',
                'period_year',
                'period_month',
                'month_name',
                'scope_key',
            ]);
        abort_unless($run, 404, 'A finalized payroll run for this period was not found.');

        $records = DB::table('payslips')
            ->join('employees', 'employees.id', '=', 'payslips.employee_id')
            ->leftJoin('branches', 'branches.id', '=', 'employees.branch_id')
            ->leftJoin('employee_statutory_details', 'employee_statutory_details.employee_id', '=', 'employees.id')
            ->where('payslips.payroll_run_id', $run->id)
            ->orderBy('employees.employee_code')
            ->get([
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'branches.name as branch_name',
                'employee_statutory_details.uan',
                'employee_statutory_details.pan',
                'employee_statutory_details.pt_state',
                'employee_statutory_details.pf_applicable',
                'payslips.gross_salary',
                'payslips.basic_salary',
                'payslips.pf_employee',
                'payslips.professional_tax',
                'payslips.tds',
            ]);

        $rows = $records->map(function ($record) use ($report) {
            $employeeName = trim($record->first_name.' '.$record->last_name);

            return match ($report) {
                'pf_source_register' => [
                    'employee_code' => $record->employee_code,
                    'employee_name' => $employeeName,
                    'uan' => $record->uan,
                    'pf_applicable' => (bool) $record->pf_applicable,
                    'gross_salary' => $record->gross_salary,
                    'basic_salary' => $record->basic_salary,
                    'pf_employee_deduction' => $record->pf_employee,
                ],
                'pt_source_register' => [
                    'employee_code' => $record->employee_code,
                    'employee_name' => $employeeName,
                    'branch_name' => $record->branch_name,
                    'pt_state' => $record->pt_state,
                    'gross_salary' => $record->gross_salary,
                    'professional_tax_recorded' => $record->professional_tax,
                ],
                default => [
                    'employee_code' => $record->employee_code,
                    'employee_name' => $employeeName,
                    'pan' => $record->pan,
                    'gross_salary' => $record->gross_salary,
                    'tds_recorded' => $record->tds,
                ],
            };
        });

        $identifier = match ($report) {
            'pf_source_register' => 'uan',
            'tds_source_register' => 'pan',
            default => 'pt_state',
        };
        $missingIdentifierCount = $rows->filter(
            fn (array $row) => trim((string) ($row[$identifier] ?? '')) === ''
        )->count();

        return response()->json([
            'data' => [
                'report' => $report,
                'run' => $run,
                'rows' => $rows,
                'missing_identifier_count' => $missingIdentifierCount,
                'disclaimer' => $this->exportDisclaimer($report),
            ],
        ]);
    }

    private function workforceSummary(?array $employeeIds): array
    {
        $employees = DB::table('employees')
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('id', $employeeIds))
            ->where('is_demo', false)
            ->get(['status', 'date_of_joining']);
        $activeEmployees = $employees->where('status', 'active')->values();
        $today = Carbon::today()->toDateString();
        $tenures = $activeEmployees
            ->filter(fn ($employee) => $employee->date_of_joining && $employee->date_of_joining <= $today)
            ->map(fn ($employee) => Carbon::parse($employee->date_of_joining)->diffInDays($today) / 365.2425);
        $activeCount = $activeEmployees->count();

        $branches = DB::table('branches')
            ->join('employees', 'employees.branch_id', '=', 'branches.id')
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employees.id', $employeeIds))
            ->where('employees.status', 'active')
            ->where('employees.is_demo', false)
            ->select('branches.id', 'branches.name', 'branches.city')
            ->selectRaw('COUNT(employees.id) as active_employee_count')
            ->groupBy('branches.id', 'branches.name', 'branches.city')
            ->orderBy('branches.name')
            ->get();

        return [
            'total_employee_count' => $employees->count(),
            'active_employee_count' => $activeCount,
            'inactive_employee_count' => $employees->count() - $activeCount,
            'average_tenure_years' => $tenures->isNotEmpty()
                ? round($tenures->avg(), 1)
                : null,
            'branches' => $branches->map(function ($branch) use ($activeCount) {
                $branch->share_percent = $activeCount
                    ? round(($branch->active_employee_count / $activeCount) * 100, 1)
                    : 0;

                return $branch;
            }),
        ];
    }

    private function projectSummary(?array $employeeIds): array
    {
        $assignments = DB::table('project_assignments')
            ->join('employees', 'employees.id', '=', 'project_assignments.employee_id')
            ->where('employees.is_demo', false)
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('project_assignments.employee_id', $employeeIds))
            ->get([
                'project_assignments.status',
                'project_assignments.active_due_date',
                'project_assignments.actual_completed_at',
            ]);
        $completed = $assignments->filter(
            fn ($assignment) => $assignment->status === 'completed' || $assignment->actual_completed_at !== null
        );
        $ratedCompletions = $completed->filter(
            fn ($assignment) => $assignment->actual_completed_at && $assignment->active_due_date
        );
        $onTimeCompletions = $ratedCompletions->filter(
            fn ($assignment) => substr((string) $assignment->actual_completed_at, 0, 10) <= $assignment->active_due_date
        )->count();
        $today = Carbon::today()->toDateString();
        $overdue = $assignments->filter(
            fn ($assignment) => ! in_array($assignment->status, ['completed', 'cancelled'], true)
                && $assignment->active_due_date
                && $assignment->active_due_date < $today
        )->count();
        $active = $assignments->reject(
            fn ($assignment) => in_array($assignment->status, ['completed', 'cancelled'], true)
        )->count();

        return [
            'active_milestone_count' => $active,
            'completed_milestone_count' => $completed->count(),
            'overdue_milestone_count' => $overdue,
            'rated_completion_count' => $ratedCompletions->count(),
            'on_time_milestone_count' => $onTimeCompletions,
            'on_time_completion_rate' => $ratedCompletions->isNotEmpty()
                ? round(($onTimeCompletions / $ratedCompletions->count()) * 100)
                : null,
        ];
    }

    private function employeeMasterRows(?array $employeeIds)
    {
        return DB::table('employees')
            ->leftJoin('branches', 'branches.id', '=', 'employees.branch_id')
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employees.id', $employeeIds))
            ->where('employees.is_demo', false)
            ->orderBy('employees.employee_code')
            ->get([
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'employees.email',
                'employees.designation',
                'employees.department',
                'branches.name as branch_name',
                'employees.status',
            ])
            ->map(fn ($employee) => [
                'employee_code' => $employee->employee_code,
                'first_name' => $employee->first_name,
                'last_name' => $employee->last_name,
                'email' => $employee->email,
                'designation' => $employee->designation,
                'department' => $employee->department,
                'branch_name' => $employee->branch_name,
                'status' => $employee->status,
            ]);
    }

    private function exportDisclaimer(string $report): string
    {
        return match ($report) {
            'pf_source_register' => 'Internal source data from published payslips and current employee statutory identifiers. This is not an EPFO ECR filing format or a contribution validation.',
            'pt_source_register' => 'Internal source data showing the professional-tax amount recorded on the published payslip and the current employee PT state. State rules are not calculated and this is not a state return form.',
            default => 'Internal source data showing the TDS amount recorded on the published payslip and the current employee PAN. This is not Form 24Q and does not include the full tax computation or challan data.',
        };
    }
}
