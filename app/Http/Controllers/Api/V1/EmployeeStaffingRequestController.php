<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class EmployeeStaffingRequestController extends Controller
{
    private const HR_ROLES = ['super_admin', 'hr_admin', 'hr_executive'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'manager' || in_array($user->role, self::HR_ROLES, true), 403);

        $query = DB::table('employee_staffing_requests')
            ->join('employees as managers', 'employee_staffing_requests.manager_employee_id', '=', 'managers.id')
            ->leftJoin('employees as assigned', 'employee_staffing_requests.assigned_employee_id', '=', 'assigned.id')
            ->select(
                'employee_staffing_requests.*',
                'managers.first_name as manager_first_name',
                'managers.last_name as manager_last_name',
                'managers.employee_code as manager_employee_code',
                'assigned.first_name as assigned_first_name',
                'assigned.last_name as assigned_last_name',
                'assigned.employee_code as assigned_employee_code'
            )
            ->orderByDesc('employee_staffing_requests.id');

        if ($user->role === 'manager') {
            abort_unless($user->employee_id, 403);
            $query->where('employee_staffing_requests.manager_employee_id', $user->employee_id);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'manager' && $user->employee_id, 403);

        $employee = DB::table('employees')
            ->where('id', $user->employee_id)
            ->where('status', 'active')
            ->whereNull('deleted_at')
            ->exists();
        abort_unless($employee, 403, 'An active employee record is required to submit a staffing request.');

        $validated = $request->validate([
            'request_type' => ['required', Rule::in(['new_hire', 'team_member'])],
            'designation' => 'required|string|max:255',
            'department' => 'required|string|max:255',
            'requested_employee_code' => 'nullable|string|max:20',
            'justification' => 'required|string|max:5000',
        ]);

        if ($validated['request_type'] === 'new_hire' && !empty($validated['requested_employee_code'])) {
            return response()->json([
                'message' => 'Employee code is only used for team-member requests.',
                'errors' => ['requested_employee_code' => ['Remove the employee code for a new-hire request.']],
            ], 422);
        }

        $id = DB::table('employee_staffing_requests')->insertGetId([
            'manager_employee_id' => $user->employee_id,
            'request_type' => $validated['request_type'],
            'designation' => trim($validated['designation']),
            'department' => trim($validated['department']),
            'requested_employee_code' => $validated['request_type'] === 'team_member'
                ? (isset($validated['requested_employee_code']) ? strtoupper(trim($validated['requested_employee_code'])) : null)
                : null,
            'justification' => trim($validated['justification']),
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        AuditLog::record(
            $request,
            'STAFFING_REQUEST_SUBMITTED',
            'Employees',
            "Submitted {$validated['request_type']} staffing request #{$id}"
        );

        return response()->json([
            'data' => DB::table('employee_staffing_requests')->where('id', $id)->first(),
        ], 201);
    }

    public function review(Request $request, int $requestId): JsonResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, self::HR_ROLES, true), 403);

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected'])],
            'assigned_employee_id' => 'nullable|integer|exists:employees,id',
            'review_note' => 'nullable|string|max:5000',
        ]);

        $result = DB::transaction(function () use ($request, $requestId, $validated, $user): object {
            $staffingRequest = DB::table('employee_staffing_requests')
                ->where('id', $requestId)
                ->lockForUpdate()
                ->first();
            abort_unless($staffingRequest, 404);
            abort_if($staffingRequest->status !== 'pending', 409, 'This staffing request has already been reviewed.');

            $assignedEmployeeId = null;
            if ($validated['decision'] === 'approved' && $staffingRequest->request_type === 'team_member') {
                if (empty($validated['assigned_employee_id'])) {
                    abort(422, 'Select an employee to assign before approving this team-member request.');
                }

                $assigned = DB::table('employees')
                    ->where('id', $validated['assigned_employee_id'])
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->first();
                abort_unless($assigned, 422, 'The selected employee is not active.');
                abort_if((int) $assigned->id === (int) $staffingRequest->manager_employee_id, 422, 'A manager cannot be assigned to their own team.');
                if ($staffingRequest->requested_employee_code
                    && strtoupper($staffingRequest->requested_employee_code) !== strtoupper($assigned->employee_code)) {
                    abort(422, 'The selected employee code does not match the manager request.');
                }

                $manager = DB::table('employees')
                    ->where('id', $staffingRequest->manager_employee_id)
                    ->where('status', 'active')
                    ->whereNull('deleted_at')
                    ->first();
                abort_unless($manager, 409, 'The requesting manager is no longer active.');
                abort_if(
                    $this->managerChainContains((int) $manager->id, (int) $assigned->id),
                    422,
                    'The employee cannot be assigned because that would create a reporting hierarchy cycle.'
                );
                if (
                    !config('hrms.allow_cross_branch_managers')
                    && (int) $assigned->branch_id !== (int) $manager->branch_id
                ) {
                    abort(422, 'The employee and manager must belong to the same branch.');
                }

                DB::table('employees')
                    ->where('id', $assigned->id)
                    ->update([
                        'reporting_manager_id' => $manager->id,
                        'version' => DB::raw('version + 1'),
                        'updated_at' => now(),
                    ]);
                $assignedEmployeeId = $assigned->id;
            }

            DB::table('employee_staffing_requests')->where('id', $requestId)->update([
                'status' => $validated['decision'],
                'assigned_employee_id' => $assignedEmployeeId,
                'reviewed_by' => $user->id,
                'review_note' => $validated['review_note'] ?? null,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            AuditLog::record(
                $request,
                'STAFFING_REQUEST_REVIEWED',
                'Employees',
                "Marked staffing request #{$requestId} {$validated['decision']}"
                    . ($assignedEmployeeId ? "; assigned employee #{$assignedEmployeeId}" : '')
            );

            return DB::table('employee_staffing_requests')->where('id', $requestId)->first();
        });

        return response()->json(['data' => $result]);
    }

    private function managerChainContains(int $managerId, int $employeeId): bool
    {
        $visited = [];
        $currentId = $managerId;
        while ($currentId > 0) {
            if ($currentId === $employeeId || isset($visited[$currentId])) {
                return true;
            }
            $visited[$currentId] = true;
            $currentId = (int) (DB::table('employees')->where('id', $currentId)->value('reporting_manager_id') ?? 0);
        }

        return false;
    }
}
