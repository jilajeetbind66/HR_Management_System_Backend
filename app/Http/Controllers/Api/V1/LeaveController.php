<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Support\RoleAccess;
use App\Support\AuditLog;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use App\Support\LeaveDayCalculator;

class LeaveController extends Controller
{
    public function balances(Request $request): JsonResponse
    {
        $request->validate(['scope' => 'sometimes|in:mine,approvals']);
        $user = $request->user();
        $query = DB::table('leave_balances')
            ->join('employees', 'leave_balances.employee_id', '=', 'employees.id')
            ->select('leave_balances.*', 'employees.date_of_joining');

        if ($user->role === 'manager' && $request->query('scope') === 'mine') {
            $query->where('leave_balances.employee_id', $user->employee_id ?? 0);
        } elseif ($user->role === 'manager') {
            $query->whereIn('leave_balances.employee_id', RoleAccess::visibleEmployeeIds($user, false));
        } elseif (!RoleAccess::isHr($user->role)) {
            $query->where('leave_balances.employee_id', $user->employee_id ?? 0);
        }

        $balances = $query->get();
        [$fiscalStart, $fiscalEnd, $today] = $this->fiscalYearWindow();
        $usage = $this->usageForEmployees(
            $balances->pluck('employee_id')->map(fn ($id) => (int) $id)->all(),
            $fiscalStart,
            $fiscalEnd
        );
        foreach ($balances as $balance) {
            $employeeUsage = $usage[$balance->employee_id] ?? [];
            foreach (['cl', 'sl', 'pl', 'comp_off'] as $column) {
                $used = (float) ($employeeUsage[$column]['approved'] ?? 0);
                $pending = (float) ($employeeUsage[$column]['pending'] ?? 0);
                $quota = ['cl' => 12.0, 'sl' => 12.0, 'pl' => 15.0][$column] ?? null;
                $accrued = $quota === null
                    ? (float) $balance->{$column}
                    : $this->proratedEntitlement($quota, $balance->date_of_joining, $fiscalStart, $fiscalEnd, $today);
                $available = $quota === null
                    ? max(0, (float) $balance->{$column})
                    : min(
                        max(0, (float) $balance->{$column}),
                        max(0, $accrued - $used)
                    );

                $balance->{$column . '_accrued'} = round($accrued, 2);
                $balance->{$column . '_available'} = round($available, 2);
                $balance->{$column . '_used'} = round($used, 2);
                $balance->{$column . '_pending'} = round($pending, 2);
                $balance->{$column} = round($available, 2);
            }
        }
        $balances = $balances->keyBy('employee_id')->all();

        return response()->json(['data' => (object) $balances]);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = DB::table('leave_requests')
            ->leftJoin('employees', 'leave_requests.employee_id', '=', 'employees.id')
            ->select(
                'leave_requests.*',
                'employees.first_name',
                'employees.last_name',
                'employees.employee_code',
                'employees.reporting_manager_id as reporting_manager_employee_id'
            )
            ->orderBy('leave_requests.id', 'desc');

        if ($user->role === 'manager' && $request->query('scope') === 'mine') {
            $query->where('leave_requests.employee_id', $user->employee_id ?? 0);
        } elseif ($user->role === 'manager') {
            $query->whereIn('leave_requests.employee_id', RoleAccess::visibleEmployeeIds($user, false));
        } elseif (!RoleAccess::isHr($user->role)) {
            $query->where('leave_requests.employee_id', $user->employee_id ?? 0);
        }

        $filters = $request->validate([
            'scope' => 'sometimes|in:mine,approvals',
            'status' => 'sometimes|in:pending,approved,rejected,cancelled',
            'employee_id' => 'sometimes|integer|exists:employees,id',
            'from_date' => 'sometimes|date',
            'to_date' => 'sometimes|date',
        ]);
        if (isset($filters['status'])) {
            $query->where('leave_requests.status', $filters['status']);
        }
        if (isset($filters['employee_id'])) {
            $query->where('leave_requests.employee_id', $filters['employee_id']);
        }
        if (isset($filters['from_date'])) {
            $query->where('leave_requests.to_date', '>=', $filters['from_date']);
        }
        if (isset($filters['to_date'])) {
            $query->where('leave_requests.from_date', '<=', $filters['to_date']);
        }

        $leaves = $query->get()->map(function ($leave) {
            $leave->employee_name = trim($leave->first_name . ' ' . $leave->last_name);

            return $leave;
        });
        $reviews = DB::table('leave_request_reviews')
            ->whereIn('leave_request_id', $leaves->pluck('id'))
            ->orderBy('id')
            ->get()
            ->groupBy('leave_request_id');
        $leaves->each(function ($leave) use ($reviews) {
            $leave->reviews = $reviews->get($leave->id, collect())->values();
        });
        $certificateIds = $leaves->pluck('certificate_upload_id')->filter()->unique()->values();
        $certificates = DB::table('hrms_uploads')
            ->whereIn('id', $certificateIds)
            ->get(['id', 'original_filename', 'file_size', 'mime_type'])
            ->keyBy('id');
        $leaves->each(function ($leave) use ($certificates, $user) {
            $canViewCertificate = RoleAccess::isHr($user->role)
                || ($user->employee_id && (int) $user->employee_id === (int) $leave->employee_id);
            $leave->certificate_upload = $canViewCertificate
                ? $certificates->get($leave->certificate_upload_id)
                : null;
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
            'days' => 'sometimes|nullable|numeric|min:0.5',
            'reason' => 'nullable|string',
            'is_half_day' => 'sometimes|boolean',
            'half_day_period' => 'required_if:is_half_day,true|nullable|in:first_half,second_half',
            'certificate_upload_id' => 'nullable|integer|exists:hrms_uploads,id',
        ]);
        $routing = $this->routingForApplicant((int) $user->employee_id, $user->role);
        $dayCalculation = LeaveDayCalculator::calculate(
            (int) $user->employee_id,
            $validated['from_date'],
            $validated['to_date'],
            (bool) ($validated['is_half_day'] ?? false)
        );
        $days = $dayCalculation['days'];
        if ($this->balanceColumnForType($validated['leave_type']) === 'sl'
            && $days > 2
            && empty($validated['certificate_upload_id'])) {
            throw ValidationException::withMessages([
                'certificate_upload_id' => 'A medical certificate is required for sick leave exceeding two days.',
            ]);
        }

        $submission = DB::transaction(function () use ($validated, $user, $request, $routing, $days, $dayCalculation) {
            $balanceColumn = $this->balanceColumnForType($validated['leave_type']);
            if (!$balanceColumn && !$this->isLopType($validated['leave_type'])) {
                throw ValidationException::withMessages([
                    'leave_type' => 'Select a supported leave type.',
                ]);
            }
            $balance = $balanceColumn
                ? DB::table('leave_balances')
                    ->where('employee_id', $user->employee_id)
                    ->lockForUpdate()
                    ->first()
                : null;

            $overlap = DB::table('leave_requests')
                ->where('employee_id', $user->employee_id)
                ->whereIn('status', ['pending', 'approved'])
                ->whereDate('from_date', '<=', $validated['to_date'])
                ->whereDate('to_date', '>=', $validated['from_date'])
                ->exists();
            if ($overlap) {
                throw ValidationException::withMessages([
                    'from_date' => 'This leave overlaps an existing pending or approved request.',
                ]);
            }

            if ($balanceColumn && (!$balance
                || $this->availableBalance((int) $user->employee_id, $balanceColumn, $balance) < $days)) {
                throw ValidationException::withMessages([
                    'days' => 'The requested leave exceeds your available balance.',
                ]);
            }

            $certificateUploadId = $validated['certificate_upload_id'] ?? null;
            if ($certificateUploadId) {
                $certificate = DB::table('hrms_uploads')
                    ->where('id', $certificateUploadId)
                    ->lockForUpdate()
                    ->first();
                if (!$certificate
                    || $certificate->module !== 'leave_certificates'
                    || (int) $certificate->employee_id !== (int) $user->employee_id
                    || $certificate->record_id !== null
                    || !in_array($certificate->mime_type, ['application/pdf', 'image/jpeg', 'image/png'], true)
                    || (int) $certificate->file_size > 10 * 1024 * 1024) {
                    throw ValidationException::withMessages([
                        'certificate_upload_id' => 'Select a private certificate uploaded to your own account.',
                    ]);
                }
            }

            $leaveId = DB::table('leave_requests')->insertGetId([
                'employee_id' => $user->employee_id,
                'leave_type' => $validated['leave_type'],
                'from_date' => $validated['from_date'],
                'to_date' => $validated['to_date'],
                'days' => $days,
                'counted_dates' => json_encode($dayCalculation['included_dates']),
                'reason' => $validated['reason'] ?? 'Personal leave request',
                'status' => 'pending',
                'approval_stage' => $routing['stage'],
                'next_approver_role' => $routing['next_approver_role'],
                'is_half_day' => (bool) ($validated['is_half_day'] ?? false),
                'half_day_period' => ($validated['is_half_day'] ?? false)
                    ? $validated['half_day_period']
                    : null,
                'certificate_upload_id' => $certificateUploadId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($certificateUploadId) {
                DB::table('hrms_uploads')->where('id', $certificateUploadId)->update([
                    'record_id' => $leaveId,
                    'updated_at' => now(),
                ]);
            }

            AuditLog::record(
                $request,
                'LEAVE_APPLIED',
                'Leaves',
                "Applied for {$days} day(s) of {$validated['leave_type']}"
            );

            $punchDates = DB::table('attendance_records')
                ->where('employee_id', $user->employee_id)
                ->whereBetween('date', [$validated['from_date'], $validated['to_date']])
                ->whereNotNull('punch_in')
                ->orderBy('date')
                ->pluck('date')
                ->all();

            return ['id' => $leaveId, 'days' => $days, 'punch_dates' => $punchDates];
        });

        $leave = DB::table('leave_requests')
            ->leftJoin('employees', 'leave_requests.employee_id', '=', 'employees.id')
            ->select(
                'leave_requests.*',
                'employees.first_name',
                'employees.last_name',
                'employees.employee_code',
                'employees.reporting_manager_id as reporting_manager_employee_id'
            )
            ->where('leave_requests.id', $submission['id'])
            ->first();
        $leave->certificate_upload = $leave->certificate_upload_id
            ? DB::table('hrms_uploads')
                ->where('id', $leave->certificate_upload_id)
                ->first(['id', 'original_filename', 'file_size', 'mime_type'])
            : null;

        $warnings = [];
        if ($dayCalculation['excluded_dates']) {
            $warnings[] = $this->formatExcludedDays($dayCalculation['excluded_dates']);
        }
        $reasonWarning = $this->leaveReasonWarning($validated['leave_type'], $validated['reason'] ?? '');
        if ($reasonWarning) {
            $warnings[] = $reasonWarning;
        }
        if ($submission['punch_dates']) {
            $warnings[] = 'Attendance punches already exist on: ' . implode(', ', $submission['punch_dates']) . '.';
        }

        return response()->json(['data' => $leave, 'warnings' => $warnings], 201);
    }

    public function review(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'status' => 'required|in:approved,rejected',
            'remarks' => 'nullable|string|max:2000',
        ]);
        $remarks = trim((string) ($validated['remarks'] ?? ''));
        if ($validated['status'] === 'rejected' && $remarks === '') {
            throw ValidationException::withMessages([
                'remarks' => 'Remarks are required when rejecting a leave request.',
            ]);
        }

        $result = DB::transaction(function () use ($id, $validated, $remarks, $user, $request) {
            $leave = DB::table('leave_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($leave, 404);
            abort_if(
                $user->employee_id && (int) $user->employee_id === (int) $leave->employee_id,
                403,
                'You cannot review your own leave request.'
            );
            abort_unless($leave->status === 'pending', 409);
            abort_unless($this->canReviewAtStage($user, $leave), 403);

            $now = now();
            $reviewedStage = $leave->approval_stage ?? 'Approval';
            $targetStatus = $validated['status'];
            $nextRouting = null;
            if ($targetStatus === 'approved' && $leave->next_approver_role === 'manager') {
                $nextRouting = ['stage' => 'HR Approval', 'next_approver_role' => 'hr'];
            } elseif ($targetStatus === 'approved'
                && $leave->next_approver_role === 'hr'
                && in_array($this->applicantRole((int) $leave->employee_id), ['hr_admin', 'hr_executive'], true)) {
                $nextRouting = ['stage' => 'Super Admin Approval', 'next_approver_role' => 'super_admin'];
            }

            if ($targetStatus === 'approved' && !$nextRouting) {
                $balanceColumn = $this->balanceColumnForType($leave->leave_type);
                if ($balanceColumn) {
                    $balance = DB::table('leave_balances')
                        ->where('employee_id', $leave->employee_id)
                        ->lockForUpdate()
                        ->first();

                    [$fiscalStart, $fiscalEnd, $today] = $this->fiscalYearWindow();
                    $usage = $this->usageForEmployees([(int) $leave->employee_id], $fiscalStart, $fiscalEnd);
                    $approvedUsage = (float) ($usage[$leave->employee_id][$balanceColumn]['approved'] ?? 0);
                    $quota = ['cl' => 12.0, 'sl' => 12.0, 'pl' => 15.0][$balanceColumn] ?? null;
                    $accrued = $quota === null || !$balance
                        ? (float) ($balance->{$balanceColumn} ?? 0)
                        : $this->proratedEntitlement(
                            $quota,
                            DB::table('employees')->where('id', $leave->employee_id)->value('date_of_joining'),
                            $fiscalStart,
                            $fiscalEnd,
                            $today
                        );
                    $available = !$balance
                        ? 0
                        : ($quota === null
                            ? max(0, (float) $balance->{$balanceColumn})
                            : min(
                                max(0, (float) $balance->{$balanceColumn}),
                                max(0, $accrued - $approvedUsage)
                            ));

                    if (!$balance || $available < (float) $leave->days) {
                        throw ValidationException::withMessages([
                            'status' => 'Insufficient or unconfigured leave balance for this request.',
                        ]);
                    }

                    DB::table('leave_balances')->where('employee_id', $leave->employee_id)->update([
                        $balanceColumn => (float) $balance->{$balanceColumn} - (float) $leave->days,
                        'updated_at' => $now,
                    ]);
                } elseif (!$this->isLopType($leave->leave_type)) {
                    throw ValidationException::withMessages([
                        'status' => 'Insufficient or unconfigured leave balance for this request.',
                    ]);
                }
            }

            $updates = [
                'reviewed_by' => $user->name,
                'reviewed_at' => $now,
                'review_remarks' => $remarks !== '' ? $remarks : null,
                'updated_at' => $now,
            ];
            if ($targetStatus === 'rejected') {
                $updates['status'] = 'rejected';
                $updates['approval_stage'] = 'Rejected';
                $updates['next_approver_role'] = null;
            } elseif ($nextRouting) {
                $updates['approval_stage'] = $nextRouting['stage'];
                $updates['next_approver_role'] = $nextRouting['next_approver_role'];
            } else {
                $updates['status'] = 'approved';
                $updates['approval_stage'] = 'Approved';
                $updates['next_approver_role'] = null;
            }
            DB::table('leave_requests')->where('id', $leave->id)->update($updates);
            if ($targetStatus === 'approved' && !$nextRouting) {
                $this->markAttendanceOnLeave($leave, $now, $request);
            }
            DB::table('leave_request_reviews')->insert([
                'leave_request_id' => $leave->id,
                'reviewer_user_id' => $user->id,
                'reviewer_name' => $user->name,
                'stage' => $reviewedStage,
                'decision' => $targetStatus,
                'remarks' => $remarks !== '' ? $remarks : null,
                'reviewed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $auditStatus = $targetStatus === 'approved' && $nextRouting
                ? "approved at {$leave->approval_stage}; routed to {$nextRouting['stage']}"
                : $targetStatus;
            AuditLog::record(
                $request,
                'LEAVE_REVIEWED',
                'Leaves',
                "Marked leave request #{$leave->id} {$auditStatus}"
            );

            $applicantUserId = DB::table('users')
                ->where('employee_id', $leave->employee_id)
                ->value('id');
            abort_unless($applicantUserId, 409, 'The leave applicant is not linked to a user account.');
            $message = $targetStatus === 'rejected'
                ? "Your leave request for {$leave->from_date} to {$leave->to_date} was rejected. Remarks: {$remarks}"
                : ($nextRouting
                    ? "Your leave request was approved at {$reviewedStage} and is now pending {$nextRouting['stage']}."
                    : "Your leave request for {$leave->from_date} to {$leave->to_date} was approved.");
            DB::table('user_notifications')->insert([
                'user_id' => $applicantUserId,
                'event_key' => 'leave.reviewed',
                'title' => $targetStatus === 'rejected' ? 'Leave request rejected' : 'Leave request updated',
                'message' => $message,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return ['status' => $targetStatus, 'approval_stage' => $nextRouting['stage'] ?? ucfirst($targetStatus)];
        });

        return response()->json([
            'message' => $result['status'] === 'approved' && $result['approval_stage'] !== 'Approved'
                ? "Leave request approved and routed to {$result['approval_stage']}."
                : "Leave request marked {$result['status']}.",
            'data' => $result,
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->employee_id, 403);

        DB::transaction(function () use ($id, $user, $request) {
            $leave = DB::table('leave_requests')->where('id', $id)->lockForUpdate()->first();
            abort_unless($leave, 404);
            abort_unless((int) $leave->employee_id === (int) $user->employee_id, 403);
            abort_unless($leave->status === 'pending', 409);

            DB::table('leave_requests')->where('id', $leave->id)->update([
                'status' => 'cancelled',
                'approval_stage' => 'Cancelled',
                'next_approver_role' => null,
                'updated_at' => now(),
            ]);
            AuditLog::record($request, 'LEAVE_CANCELLED', 'Leaves', "Cancelled leave request #{$leave->id}");
        });

        return response()->json(['message' => 'Leave request cancelled.']);
    }

    private function routingForApplicant(int $employeeId, string $applicantRole): array
    {
        if ($applicantRole === 'super_admin') {
            return ['stage' => 'Super Admin / HR Head Approval', 'next_approver_role' => 'super_admin_or_hr_head'];
        }
        if (in_array($applicantRole, ['hr_admin', 'hr_executive'], true)) {
            return ['stage' => 'Super Admin Approval', 'next_approver_role' => 'super_admin'];
        }
        if ($applicantRole === 'manager') {
            return ['stage' => 'HR Approval', 'next_approver_role' => 'hr'];
        }

        $managerId = DB::table('employees')->where('id', $employeeId)->value('reporting_manager_id');
        if ($managerId) {
            $managerUser = DB::table('users')
                ->join('employees', 'employees.id', '=', 'users.employee_id')
                ->where('users.employee_id', $managerId)
                ->whereNull('employees.deleted_at')
                ->first(['users.role']);
            if ($managerUser?->role === 'super_admin') {
                return ['stage' => 'Super Admin Approval', 'next_approver_role' => 'super_admin'];
            }
            if (in_array($managerUser?->role, ['hr_admin', 'hr_executive'], true)) {
                return ['stage' => 'HR Approval', 'next_approver_role' => 'hr'];
            }
            if ($managerUser?->role === 'manager') {
                return ['stage' => 'Manager Approval', 'next_approver_role' => 'manager'];
            }
        }

        return ['stage' => 'No manager assigned', 'next_approver_role' => 'hr'];
    }

    private function applicantRole(int $employeeId): string
    {
        return DB::table('users')->where('employee_id', $employeeId)->value('role') ?? 'employee';
    }

    private function canReviewAtStage(object $user, object $leave): bool
    {
        return match ($leave->next_approver_role) {
            'manager' => $user->role === 'manager'
                && RoleAccess::managesEmployee($user, (int) $leave->employee_id),
            'hr' => in_array($user->role, ['hr_admin', 'hr_executive', 'super_admin'], true),
            'super_admin' => $user->role === 'super_admin',
            'super_admin_or_hr_head' => in_array($user->role, ['super_admin', 'hr_admin'], true),
            default => false,
        };
    }

    private function fiscalYearWindow(): array
    {
        $today = now()->startOfDay();
        $startYear = $today->month >= 4 ? $today->year : $today->year - 1;
        $start = Carbon::create($startYear, 4, 1)->startOfDay();

        return [$start, $start->copy()->addYear()->subDay()->endOfDay(), $today];
    }

    private function proratedEntitlement(
        float $annualQuota,
        ?string $dateOfJoining,
        Carbon $fiscalStart,
        Carbon $fiscalEnd,
        Carbon $today
    ): float {
        if (!$dateOfJoining) {
            return 0;
        }

        $joiningDate = Carbon::parse($dateOfJoining)->startOfDay();
        $eligibleStart = $joiningDate->greaterThan($fiscalStart) ? $joiningDate : $fiscalStart;
        $eligibleEnd = $today->lessThan($fiscalEnd) ? $today : $fiscalEnd;
        if ($eligibleStart->greaterThan($eligibleEnd)) {
            return 0;
        }

        $eligibleDays = $eligibleStart->diffInDays($eligibleEnd) + 1;
        $fiscalYearDays = $fiscalStart->diffInDays($fiscalEnd) + 1;

        return round($annualQuota * $eligibleDays / $fiscalYearDays, 2);
    }

    private function usageForEmployees(array $employeeIds, Carbon $fiscalStart, Carbon $fiscalEnd): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $requests = DB::table('leave_requests')
            ->whereIn('employee_id', $employeeIds)
            ->whereIn('status', ['approved', 'pending'])
            ->whereDate('from_date', '<=', $fiscalEnd->toDateString())
            ->whereDate('to_date', '>=', $fiscalStart->toDateString())
            ->get(['employee_id', 'leave_type', 'status', 'days']);
        $usage = [];
        foreach ($requests as $request) {
            $column = $this->balanceColumnForType($request->leave_type);
            if (!$column) {
                continue;
            }
            $employeeId = (int) $request->employee_id;
            $usage[$employeeId][$column][$request->status] =
                ($usage[$employeeId][$column][$request->status] ?? 0) + (float) $request->days;
        }

        return $usage;
    }

    private function balanceColumnForType(string $leaveType): ?string
    {
        $type = strtolower($leaveType);

        return match (true) {
            str_contains($type, 'casual') || str_contains($type, '(cl)') => 'cl',
            str_contains($type, 'sick') || str_contains($type, '(sl)') => 'sl',
            str_contains($type, 'privilege') || str_contains($type, '(pl)') => 'pl',
            str_contains($type, 'comp') => 'comp_off',
            default => null,
        };
    }

    private function isLopType(string $leaveType): bool
    {
        $type = strtolower(trim($leaveType));

        return $type === 'leave without pay (lop)';
    }

    private function markAttendanceOnLeave(object $leave, Carbon $now, Request $request): void
    {
        $countedDates = json_decode((string) ($leave->counted_dates ?? ''), true);
        if (!is_array($countedDates) || $countedDates === []) {
            $countedDates = LeaveDayCalculator::calculate(
                (int) $leave->employee_id,
                $leave->from_date,
                $leave->to_date,
                (bool) ($leave->is_half_day ?? false)
            )['included_dates'];
        }
        $markedDates = [];

        foreach ($countedDates as $date) {
            $attendance = DB::table('attendance_records')
                ->where('employee_id', $leave->employee_id)
                ->whereDate('date', $date)
                ->lockForUpdate()
                ->first();

            if (!$attendance) {
                DB::table('attendance_records')->insert([
                    'employee_id' => $leave->employee_id,
                    'date' => $date,
                    'status' => 'On Leave',
                    'work_hours' => 0,
                    'location' => 'Approved Leave',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $markedDates[] = $date;
            } elseif (!$attendance->punch_in) {
                DB::table('attendance_records')->where('id', $attendance->id)->update([
                    'status' => 'On Leave',
                    'updated_at' => $now,
                ]);
                $markedDates[] = $date;
            }
        }

        if ($markedDates) {
            AuditLog::record(
                $request,
                'LEAVE_ATTENDANCE_MARKED',
                'Attendance',
                "Marked employee #{$leave->employee_id} On Leave for " . implode(', ', $markedDates)
            );
        }
    }

    private function availableBalance(int $employeeId, string $column, object $balance): float
    {
        $quota = ['cl' => 12.0, 'sl' => 12.0, 'pl' => 15.0][$column] ?? null;
        if ($quota === null) {
            return max(0, (float) $balance->{$column});
        }

        [$fiscalStart, $fiscalEnd, $today] = $this->fiscalYearWindow();
        $usage = $this->usageForEmployees([$employeeId], $fiscalStart, $fiscalEnd);
        $used = (float) ($usage[$employeeId][$column]['approved'] ?? 0);
        $dateOfJoining = DB::table('employees')->where('id', $employeeId)->value('date_of_joining');
        $accrued = $this->proratedEntitlement($quota, $dateOfJoining, $fiscalStart, $fiscalEnd, $today);

        return min(max(0, (float) $balance->{$column}), max(0, $accrued - $used));
    }

    private function formatExcludedDays(array $dates): string
    {
        $daysOfWeek = array_values(array_unique(array_map(
            fn ($date) => Carbon::parse($date)->format('l'),
            $dates
        )));
        $dayLabel = count($daysOfWeek) > 1
            ? implode(', ', array_slice($daysOfWeek, 0, -1)) . ' and ' . end($daysOfWeek)
            : $daysOfWeek[0];
        $count = count($dates);

        return $count . ($count === 1 ? ' day, ' : ' days, ')
            . $dayLabel . ' excluded by the weekend/holiday policy.';
    }

    private function leaveReasonWarning(string $leaveType, string $reason): ?string
    {
        $column = $this->balanceColumnForType($leaveType);
        $medicalReason = preg_match('/\b(fever|illness|sick|medical|doctor|hospital|injury|pain)\b/i', $reason) === 1;
        $personalReason = preg_match('/\b(personal|family|wedding|vacation|travel|bank|appointment|event)\b/i', $reason) === 1;

        if ($column === 'cl' && $medicalReason) {
            return 'Your reason mentions a medical concern. Consider whether Sick Leave is more appropriate.';
        }
        if ($column === 'sl' && $personalReason && !$medicalReason) {
            return 'Your reason sounds non-medical. Consider whether Casual or Privilege Leave is more appropriate.';
        }

        return null;
    }
}
