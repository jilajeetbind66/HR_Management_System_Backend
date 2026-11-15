<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Support\RoleAccess;
use App\Support\AuditLog;
use Illuminate\Validation\ValidationException;

class LeaveController extends Controller
{
    public function balances(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = DB::table('leave_balances')
            ->join('employees', 'leave_balances.employee_id', '=', 'employees.id')
            ->select('leave_balances.*');

        if ($user->role === 'employee') {
            $query->where('leave_balances.employee_id', $user->employee_id);
        } elseif ($user->role === 'manager') {
            $query->whereIn('leave_balances.employee_id', RoleAccess::visibleEmployeeIds($user));
        }

        $balances = $query->get()->keyBy('employee_id')->all();

        return response()->json(['data' => (object) $balances]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = DB::table('leave_requests')
            ->leftJoin('employees', 'leave_requests.employee_id', '=', 'employees.id')
            ->select('leave_requests.*', 'employees.first_name', 'employees.last_name', 'employees.employee_code')
            ->orderBy('leave_requests.id', 'desc');

        if (in_array($user->role, ['employee', 'manager'], true)) {
            $query->whereIn('leave_requests.employee_id', RoleAccess::visibleEmployeeIds($user));
        }

        $leaves = $query->get()->map(function ($leave) {
            $leave->employee_name = trim($leave->first_name . ' ' . $leave->last_name);

            return $leave;
        });

        return response()->json(['data' => $leaves]);
    }

    public function apply(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->employee_id, 403);
        abort_if(
            $request->filled('employee_id') && (int) $request->input('employee_id') !== (int) $user->employee_id,
            403
        );

        $validated = $request->validate([
            'employee_id' => 'nullable|integer|exists:employees,id',
            'leave_type' => 'required|string',
            'from_date' => 'required|date',
            'to_date' => 'required|date|after_or_equal:from_date',
            'days' => 'required|numeric|min:0.5',
            'reason' => 'nullable|string',
        ]);

        $id = DB::transaction(function () use ($validated, $user, $request) {
            $leaveId = DB::table('leave_requests')->insertGetId([
                'employee_id' => $user->employee_id,
                'leave_type' => $validated['leave_type'],
                'from_date' => $validated['from_date'],
                'to_date' => $validated['to_date'],
                'days' => $validated['days'],
                'reason' => $validated['reason'] ?? 'Personal leave request',
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            AuditLog::record(
                $request,
                'LEAVE_APPLIED',
                'Leaves',
                "Applied for {$validated['days']} day(s) of {$validated['leave_type']}"
            );

            return $leaveId;
        });

        $leave = DB::table('leave_requests')
            ->leftJoin('employees', 'leave_requests.employee_id', '=', 'employees.id')
            ->select('leave_requests.*', 'employees.first_name', 'employees.last_name', 'employees.employee_code')
            ->where('leave_requests.id', $id)
            ->first();

        return response()->json(['data' => $leave], 201);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'status' => 'required|in:approved,rejected',
        ]);

        $leave = DB::table('leave_requests')->where('id', $id)->first();
        abort_unless($leave, 404);
        abort_unless($leave->status === 'pending', 409);
        if ($user->role === 'manager') {
            abort_unless(RoleAccess::managesEmployee($user, (int) $leave->employee_id), 403);
        }

        DB::transaction(function () use ($leave, $validated, $user, $request) {
            if ($validated['status'] === 'approved') {
                $type = strtolower($leave->leave_type);
                $balanceColumn = match (true) {
                    str_contains($type, 'casual') || str_contains($type, '(cl)') => 'cl',
                    str_contains($type, 'sick') || str_contains($type, '(sl)') => 'sl',
                    str_contains($type, 'privilege') || str_contains($type, '(pl)') => 'pl',
                    str_contains($type, 'comp') => 'comp_off',
                    default => null,
                };
                $balance = DB::table('leave_balances')
                    ->where('employee_id', $leave->employee_id)
                    ->lockForUpdate()
                    ->first();

                if (!$balanceColumn || !$balance || (float) $balance->{$balanceColumn} < (float) $leave->days) {
                    throw ValidationException::withMessages([
                        'status' => 'Insufficient or unconfigured leave balance for this request.',
                    ]);
                }

                DB::table('leave_balances')->where('employee_id', $leave->employee_id)->update([
                    $balanceColumn => (float) $balance->{$balanceColumn} - (float) $leave->days,
                    'updated_at' => now(),
                ]);
            }

            DB::table('leave_requests')->where('id', $leave->id)->update([
                'status' => $validated['status'],
                'reviewed_by' => $user->name,
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);

            AuditLog::record(
                $request,
                'LEAVE_REVIEWED',
                'Leaves',
                "Marked leave request #{$leave->id} {$validated['status']}"
            );
        });

        return response()->json(['message' => "Leave request marked {$validated['status']}"]);
    }
}
