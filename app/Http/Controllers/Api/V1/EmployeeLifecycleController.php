<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EmployeeLifecycleController extends Controller
{
    public function index(): JsonResponse
    {
        $cases = DB::table('employee_lifecycle_cases')
            ->join('employees', 'employee_lifecycle_cases.employee_id', '=', 'employees.id')
            ->leftJoin('users as creators', 'employee_lifecycle_cases.created_by_user_id', '=', 'creators.id')
            ->select(
                'employee_lifecycle_cases.*',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'employees.status as employee_status',
                'creators.name as created_by_name'
            )
            ->orderByDesc('employee_lifecycle_cases.id')
            ->get();

        $tasksByCase = DB::table('employee_lifecycle_tasks')
            ->whereIn('lifecycle_case_id', $cases->pluck('id'))
            ->orderBy('id')
            ->get()
            ->groupBy('lifecycle_case_id');

        $cases->each(function ($case) use ($tasksByCase) {
            $case->tasks = $tasksByCase->get($case->id, collect())->values();
        });

        return response()->json(['data' => $cases]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => [
                'required',
                'integer',
                Rule::exists('employees', 'id')->whereNull('deleted_at'),
            ],
            'case_type' => 'required|in:onboarding,offboarding',
            'notice_date' => 'nullable|required_if:case_type,offboarding|date_format:Y-m-d|before_or_equal:effective_date',
            'effective_date' => 'required|date_format:Y-m-d',
            'target_completion_date' => 'nullable|date_format:Y-m-d|after_or_equal:effective_date',
            'reason' => 'nullable|string|max:2000',
        ]);

        $caseId = DB::transaction(function () use ($validated, $request) {
            DB::table('employees')
                ->where('id', $validated['employee_id'])
                ->lockForUpdate()
                ->first();

            $alreadyActive = DB::table('employee_lifecycle_cases')
                ->where('employee_id', $validated['employee_id'])
                ->where('case_type', $validated['case_type'])
                ->where('status', 'active')
                ->exists();

            if ($alreadyActive) {
                throw ValidationException::withMessages([
                    'employee_id' => 'This employee already has an active case of this type.',
                ]);
            }

            $effectiveDate = Carbon::parse($validated['effective_date']);
            $targetDate = isset($validated['target_completion_date'])
                ? Carbon::parse($validated['target_completion_date'])
                : ($validated['case_type'] === 'onboarding'
                    ? $effectiveDate->copy()->addDays(30)
                    : $effectiveDate->copy());
            $now = now();

            $id = DB::table('employee_lifecycle_cases')->insertGetId([
                'employee_id' => $validated['employee_id'],
                'case_type' => $validated['case_type'],
                'notice_date' => $validated['notice_date'] ?? null,
                'effective_date' => $effectiveDate->toDateString(),
                'target_completion_date' => $targetDate->toDateString(),
                'status' => 'active',
                'reason' => $validated['reason'] ?? null,
                'created_by_user_id' => $request->user()->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($this->taskTemplates($validated['case_type']) as [$department, $title, $daysFromStart]) {
                $dueDate = $validated['case_type'] === 'onboarding'
                    ? $effectiveDate->copy()->addDays($daysFromStart)->min($targetDate)
                    : $targetDate->copy();

                DB::table('employee_lifecycle_tasks')->insert([
                    'lifecycle_case_id' => $id,
                    'department' => $department,
                    'title' => $title,
                    'due_date' => $dueDate->toDateString(),
                    'status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            if ($validated['case_type'] === 'offboarding') {
                DB::table('employees')
                    ->where('id', $validated['employee_id'])
                    ->where('status', 'active')
                    ->update(['status' => 'on_notice', 'updated_at' => $now]);
            }

            $employeeCode = DB::table('employees')
                ->where('id', $validated['employee_id'])
                ->value('employee_code');
            AuditLog::record(
                $request,
                'LIFECYCLE_CASE_CREATED',
                'Employee Lifecycle',
                "Created {$validated['case_type']} case for {$employeeCode}"
            );

            return $id;
        });

        return response()->json(['data' => $this->loadCase($caseId)], 201);
    }

    public function updateTask(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,in_progress,completed,waived',
            'notes' => 'nullable|string|max:2000',
        ]);

        if ($validated['status'] === 'waived' && blank($validated['notes'] ?? null)) {
            throw ValidationException::withMessages([
                'notes' => 'A note is required when a lifecycle task is waived.',
            ]);
        }

        $caseId = DB::transaction(function () use ($validated, $request, $id) {
            $task = DB::table('employee_lifecycle_tasks')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            abort_if(!$task, 404);

            $case = DB::table('employee_lifecycle_cases')
                ->where('id', $task->lifecycle_case_id)
                ->lockForUpdate()
                ->first();
            abort_if(!$case, 404);
            abort_if($case->status !== 'active', 409, 'Completed lifecycle cases cannot be changed.');

            $isClosedTask = in_array($validated['status'], ['completed', 'waived'], true);
            DB::table('employee_lifecycle_tasks')
                ->where('id', $id)
                ->update([
                    'status' => $validated['status'],
                    'notes' => $validated['notes'] ?? null,
                    'completed_by_user_id' => $isClosedTask ? $request->user()->id : null,
                    'completed_at' => $isClosedTask ? now() : null,
                    'updated_at' => now(),
                ]);

            $hasOpenTasks = DB::table('employee_lifecycle_tasks')
                ->where('lifecycle_case_id', $case->id)
                ->whereNotIn('status', ['completed', 'waived'])
                ->exists();

            if (!$hasOpenTasks && $case->case_type === 'onboarding') {
                DB::table('employee_lifecycle_cases')
                    ->where('id', $case->id)
                    ->update(['status' => 'completed', 'updated_at' => now()]);
            }

            AuditLog::record(
                $request,
                'LIFECYCLE_TASK_UPDATED',
                'Employee Lifecycle',
                "Updated lifecycle task #{$id} to {$validated['status']}"
            );

            return (int) $case->id;
        });

        return response()->json(['data' => $this->loadCase($caseId)]);
    }

    public function relieveCase(Request $request, int $id): JsonResponse
    {
        $case = DB::table('employee_lifecycle_cases')
            ->where('id', $id)
            ->first();

        abort_unless($case, 404, 'Lifecycle case not found.');
        abort_unless($case->case_type === 'offboarding', 400, 'Only offboarding cases can be relieved.');

        $hasOpenTasks = DB::table('employee_lifecycle_tasks')
            ->where('lifecycle_case_id', $case->id)
            ->whereNotIn('status', ['completed', 'waived'])
            ->exists();

        if ($hasOpenTasks) {
            throw ValidationException::withMessages([
                'status' => 'All departmental clearances (Manager, IT, Finance, Admin, HR) must be completed or waived before final relieving.',
            ]);
        }

        DB::transaction(function () use ($case, $request, $id) {
            DB::table('employee_lifecycle_cases')
                ->where('id', $id)
                ->update([
                    'status' => 'completed',
                    'updated_at' => now(),
                ]);

            DB::table('employees')
                ->where('id', $case->employee_id)
                ->update([
                    'status' => 'inactive',
                    'updated_at' => now(),
                ]);

            AuditLog::record(
                $request,
                'EMPLOYEE_FINAL_RELIEVED',
                'Employee Lifecycle',
                "Super Admin approved final exit sign-off and relieved employee #{$case->employee_id}"
            );
        });

        return response()->json([
            'message' => 'Employee officially relieved. Employment status set to inactive.',
            'data' => $this->loadCase($id)
        ]);
    }

    private function loadCase(int $id): object
    {
        $case = DB::table('employee_lifecycle_cases')
            ->join('employees', 'employee_lifecycle_cases.employee_id', '=', 'employees.id')
            ->leftJoin('users as creators', 'employee_lifecycle_cases.created_by_user_id', '=', 'creators.id')
            ->select(
                'employee_lifecycle_cases.*',
                'employees.employee_code',
                'employees.first_name',
                'employees.last_name',
                'employees.status as employee_status',
                'creators.name as created_by_name'
            )
            ->where('employee_lifecycle_cases.id', $id)
            ->first();
        abort_if(!$case, 404);

        $case->tasks = DB::table('employee_lifecycle_tasks')
            ->where('lifecycle_case_id', $id)
            ->orderBy('id')
            ->get();

        return $case;
    }

    private function taskTemplates(string $caseType): array
    {
        if ($caseType === 'onboarding') {
            return [
                ['HR', 'Collect employment documents and complete induction', 1],
                ['IT', 'Provision system access and assign equipment', 3],
                ['Manager', 'Set role expectations and first-month goals', 7],
                ['HR', 'Complete the 30-day onboarding review', 30],
            ];
        }

        return [
            ['Manager', 'Complete knowledge transfer and work handover', 0],
            ['IT', 'Recover company equipment and revoke system access', 0],
            ['Finance', 'Review outstanding advances and final-pay inputs', 0],
            ['Admin', 'Recover access cards and company property', 0],
            ['HR', 'Complete exit interview and final clearance', 0],
        ];
    }
}
