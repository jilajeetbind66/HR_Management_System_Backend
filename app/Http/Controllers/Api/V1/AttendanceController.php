<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Support\RoleAccess;
use Illuminate\Support\Carbon;
use App\Support\AuditLog;
use App\Support\AttendanceStatus;

class AttendanceController extends Controller
{
    private const ATTENDANCE_TIMEZONE = 'Asia/Kolkata';

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = DB::table('attendance_records')
            ->leftJoin('employees', 'attendance_records.employee_id', '=', 'employees.id')
            ->select('attendance_records.*', 'employees.first_name', 'employees.last_name', 'employees.employee_code')
            ->orderBy('attendance_records.date', 'desc');

        if ($user->role === 'manager') {
            $query->whereIn('attendance_records.employee_id', RoleAccess::visibleEmployeeIds($user));
        } elseif (!RoleAccess::isHr($user->role)) {
            $query->where('attendance_records.employee_id', $user->employee_id ?? 0);
        }

        $attendance = $query->get();

        return response()->json(['data' => $attendance]);
    }

    public function punch(Request $request): JsonResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'location' => 'nullable|string'
        ]);

        abort_unless($user->employee_id && (int) $user->employee_id === (int) $validated['employee_id'], 403);

        $now = Carbon::now(self::ATTENDANCE_TIMEZONE);
        $today = $now->toDateString();
        $timeNow = $now->format('h:i A');

        $record = DB::table('attendance_records')
            ->where('employee_id', $validated['employee_id'])
            ->where('date', $today)
            ->first();

        $fetchPunch = function (int $id) {
            return DB::table('attendance_records')
                ->leftJoin('employees', 'attendance_records.employee_id', '=', 'employees.id')
                ->select('attendance_records.*', 'employees.first_name', 'employees.last_name', 'employees.employee_code')
                ->where('attendance_records.id', $id)
                ->first();
        };

        if (!$record || !$record->punch_in) {
            $id = DB::transaction(function () use ($validated, $today, $timeNow, $request, $record) {
                if ($record) {
                    DB::table('attendance_records')->where('id', $record->id)->update([
                        'punch_in' => $timeNow,
                        'punch_out' => null,
                        'status' => 'Present',
                        'work_hours' => 0,
                        'ip_address' => $request->ip(),
                        'location' => $validated['location'] ?? 'Web Portal',
                        'updated_at' => now(),
                    ]);
                    AuditLog::record($request, 'ATTENDANCE_PUNCHED_IN', 'Attendance', "Punched in for {$today}");
                    return $record->id;
                }

                $attendanceId = DB::table('attendance_records')->insertGetId([
                    'employee_id' => $validated['employee_id'],
                    'date' => $today,
                    'punch_in' => $timeNow,
                    'punch_out' => null,
                    'status' => 'Present',
                    'work_hours' => 0,
                    'ip_address' => $request->ip(),
                    'location' => $validated['location'] ?? 'Web Portal',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                AuditLog::record($request, 'ATTENDANCE_PUNCHED_IN', 'Attendance', "Punched in for {$today}");

                return $attendanceId;
            });

            return response()->json([
                'type' => 'in',
                'data' => $fetchPunch($id)
            ]);
        } elseif (!$record->punch_out) {
            $punchInAt = Carbon::createFromFormat(
                'Y-m-d h:i A',
                "{$record->date} {$record->punch_in}",
                self::ATTENDANCE_TIMEZONE
            );
            $workHours = max(0, round($punchInAt->diffInMinutes($now) / 60, 2));

            DB::transaction(function () use ($record, $timeNow, $today, $workHours, $request) {
                DB::table('attendance_records')->where('id', $record->id)->update([
                    'punch_out' => $timeNow,
                    'work_hours' => $workHours,
                    'status' => AttendanceStatus::forWorkHours($workHours),
                    'updated_at' => now(),
                ]);
                AuditLog::record($request, 'ATTENDANCE_PUNCHED_OUT', 'Attendance', "Punched out for {$today}");
            });

            return response()->json([
                'type' => 'out',
                'data' => $fetchPunch($record->id)
            ]);
        }

        return response()->json(['type' => 'already_completed', 'data' => $fetchPunch($record->id)]);
    }

    public function regularizations(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = DB::table('attendance_regularization_requests')
            ->join('employees', 'attendance_regularization_requests.employee_id', '=', 'employees.id')
            ->select(
                'attendance_regularization_requests.*',
                'employees.first_name',
                'employees.last_name',
                'employees.employee_code'
            )
            ->orderByDesc('attendance_regularization_requests.id');

        if ($user->role === 'manager') {
            $query->whereIn(
                'attendance_regularization_requests.employee_id',
                RoleAccess::visibleEmployeeIds($user)
            );
        } elseif (!RoleAccess::isHr($user->role)) {
            $query->where('attendance_regularization_requests.employee_id', $user->employee_id ?? 0);
        }

        $requests = $query->get()->map(function ($record) {
            $record->employee_name = trim($record->first_name . ' ' . $record->last_name);

            return $record;
        });

        return response()->json(['data' => $requests]);
    }

    public function requestRegularization(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->employee_id, 403);

        $validated = $request->validate([
            'date' => 'required|date',
            'punch_in_requested' => 'required|date_format:h:i A',
            'punch_out_requested' => 'required|date_format:h:i A',
            'reason' => 'required|string',
        ]);

        $punchIn = Carbon::createFromFormat('Y-m-d h:i A', $validated['date'] . ' ' . $validated['punch_in_requested']);
        $punchOut = Carbon::createFromFormat('Y-m-d h:i A', $validated['date'] . ' ' . $validated['punch_out_requested']);
        if (!$punchIn || !$punchOut || !$punchOut->greaterThan($punchIn)) {
            return response()->json([
                'message' => 'Punch out must be later than punch in for the selected date.',
                'errors' => ['punch_out_requested' => ['Punch out must be later than punch in.']],
            ], 422);
        }

        $id = DB::transaction(function () use ($user, $validated, $request) {
            $requestId = DB::table('attendance_regularization_requests')->insertGetId([
                'employee_id' => $user->employee_id,
                'date' => $validated['date'],
                'punch_in_requested' => $validated['punch_in_requested'],
                'punch_out_requested' => $validated['punch_out_requested'],
                'reason' => $validated['reason'],
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            AuditLog::record(
                $request,
                'ATTENDANCE_REGULARIZATION_SUBMITTED',
                'Attendance',
                "Requested regularization for {$validated['date']}"
            );

            return $requestId;
        });

        return response()->json([
            'data' => DB::table('attendance_regularization_requests')
                ->join('employees', 'attendance_regularization_requests.employee_id', '=', 'employees.id')
                ->select(
                    'attendance_regularization_requests.*',
                    'employees.first_name',
                    'employees.last_name',
                    'employees.employee_code'
                )
                ->where('attendance_regularization_requests.id', $id)
                ->first(),
        ], 201);
    }

    public function reviewRegularization(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate(['status' => 'required|in:approved,rejected']);
        $regularization = DB::table('attendance_regularization_requests')->where('id', $id)->first();
        abort_unless($regularization, 404);
        abort_unless($regularization->status === 'pending', 409);
        abort_if(
            $request->user()->employee_id && (int) $request->user()->employee_id === (int) $regularization->employee_id,
            403,
            'You cannot review your own regularization request.'
        );

        if ($request->user()->role === 'manager') {
            abort_unless(
                RoleAccess::managesEmployee($request->user(), (int) $regularization->employee_id),
                403
            );
        }

        DB::transaction(function () use ($regularization, $validated, $request) {
            DB::table('attendance_regularization_requests')->where('id', $regularization->id)->update([
                'status' => $validated['status'],
                'approved_by' => $request->user()->name,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);

            if ($validated['status'] === 'approved') {
                $punchIn = Carbon::createFromFormat(
                    'Y-m-d h:i A',
                    $regularization->date . ' ' . $regularization->punch_in_requested
                );
                $punchOut = Carbon::createFromFormat(
                    'Y-m-d h:i A',
                    $regularization->date . ' ' . $regularization->punch_out_requested
                );
                $workHours = round($punchIn->diffInMinutes($punchOut) / 60, 2);

                DB::table('attendance_records')->updateOrInsert(
                    [
                        'employee_id' => $regularization->employee_id,
                        'date' => $regularization->date,
                    ],
                    [
                        'punch_in' => $regularization->punch_in_requested,
                        'punch_out' => $regularization->punch_out_requested,
                        'status' => AttendanceStatus::forWorkHours($workHours),
                        'work_hours' => $workHours,
                        'location' => 'Regularized',
                        'updated_at' => now(),
                    ]
                );
            }

            AuditLog::record(
                $request,
                'ATTENDANCE_REGULARIZATION_REVIEWED',
                'Attendance',
                "Marked regularization #{$regularization->id} {$validated['status']}"
            );
        });

        return response()->json(['message' => "Regularization {$validated['status']}"]);
    }
}
