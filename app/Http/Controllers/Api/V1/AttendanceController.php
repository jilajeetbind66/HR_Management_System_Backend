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
            'location' => 'nullable|string',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        abort_unless($user->employee_id && (int) $user->employee_id === (int) $validated['employee_id'], 403);

        $now = Carbon::now(self::ATTENDANCE_TIMEZONE);
        $today = $now->toDateString();
        $timeNow = $now->format('h:i A');

        // GPS Geofence Verification
        $emp = DB::table('employees')->where('id', $validated['employee_id'])->first();
        $branch = ($emp && $emp->branch_id) ? DB::table('branches')->where('id', $emp->branch_id)->first() : null;

        $distanceMeters = null;
        $geofenceStatus = 'verified';

        if ($branch && $branch->geofence_enabled && $branch->latitude !== null && $branch->longitude !== null) {
            $hasCoords = isset($validated['latitude']) && isset($validated['longitude']) && $validated['latitude'] !== null && $validated['longitude'] !== null;
            if (!$hasCoords) {
                return response()->json([
                    'message' => "GPS Location is required for {$branch->name}. Please enable location permissions in your browser to punch attendance.",
                    'code' => 'LOCATION_REQUIRED',
                ], 422);
            }

            $userLat = (float) $validated['latitude'];
            $userLng = (float) $validated['longitude'];
            $branchLat = (float) $branch->latitude;
            $branchLng = (float) $branch->longitude;

            $distanceMeters = $this->calculateDistanceMeters($branchLat, $branchLng, $userLat, $userLng);
            $allowedRadius = (int) ($branch->radius_meters ?: 150);

            if ($distanceMeters > $allowedRadius) {
                $roundedDistance = round($distanceMeters);
                return response()->json([
                    'message' => "Punch blocked: You are {$roundedDistance}m away from {$branch->name}. You must be within {$allowedRadius}m of the office to punch.",
                    'code' => 'GEOFENCE_VIOLATION',
                    'distance_meters' => round($distanceMeters, 1),
                    'allowed_radius' => $allowedRadius,
                ], 422);
            }

            $geofenceStatus = 'within_geofence';
        }

        $locationLabel = $validated['location'] ?? ($branch ? $branch->name : 'Web Portal');
        if ($distanceMeters !== null) {
            $locationLabel .= ' (' . round($distanceMeters) . 'm)';
        }

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
            $id = DB::transaction(function () use ($validated, $today, $timeNow, $request, $record, $distanceMeters, $geofenceStatus, $locationLabel) {
                if ($record) {
                    DB::table('attendance_records')->where('id', $record->id)->update([
                        'punch_in' => $timeNow,
                        'punch_out' => null,
                        'status' => 'Present',
                        'work_hours' => 0,
                        'ip_address' => $request->ip(),
                        'latitude' => isset($validated['latitude']) ? (float)$validated['latitude'] : null,
                        'longitude' => isset($validated['longitude']) ? (float)$validated['longitude'] : null,
                        'distance_meters' => $distanceMeters !== null ? round($distanceMeters, 2) : null,
                        'geofence_status' => $geofenceStatus,
                        'location' => $locationLabel,
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
                    'latitude' => isset($validated['latitude']) ? (float)$validated['latitude'] : null,
                    'longitude' => isset($validated['longitude']) ? (float)$validated['longitude'] : null,
                    'distance_meters' => $distanceMeters !== null ? round($distanceMeters, 2) : null,
                    'geofence_status' => $geofenceStatus,
                    'location' => $locationLabel,
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

            DB::transaction(function () use ($record, $timeNow, $today, $workHours, $request, $validated, $distanceMeters, $geofenceStatus, $locationLabel) {
                DB::table('attendance_records')->where('id', $record->id)->update([
                    'punch_out' => $timeNow,
                    'work_hours' => $workHours,
                    'status' => AttendanceStatus::forWorkHours($workHours, $request->user()?->role),
                    'latitude' => isset($validated['latitude']) ? (float)$validated['latitude'] : $record->latitude,
                    'longitude' => isset($validated['longitude']) ? (float)$validated['longitude'] : $record->longitude,
                    'distance_meters' => $distanceMeters !== null ? round($distanceMeters, 2) : $record->distance_meters,
                    'geofence_status' => $geofenceStatus,
                    'location' => $locationLabel,
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

    private function calculateDistanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371000; // in meters
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    public function regularizations(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = DB::table('attendance_regularization_requests')
            ->join('employees', 'attendance_regularization_requests.employee_id', '=', 'employees.id')
            ->leftJoin('branches', 'employees.branch_id', '=', 'branches.id')
            ->select(
                'attendance_regularization_requests.*',
                'employees.first_name',
                'employees.last_name',
                'employees.employee_code',
                'branches.name as branch_name'
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

            // Notify Manager / Super Admins
            $recipientIds = [];
            $reportingManagerId = DB::table('employees')->where('id', $user->employee_id)->value('reporting_manager_id');
            if ($reportingManagerId) {
                $managerUserId = DB::table('users')->where('employee_id', $reportingManagerId)->value('id');
                if ($managerUserId && (int)$managerUserId !== $user->id) {
                    $recipientIds[] = (int)$managerUserId;
                }
            }
            $superAdminIds = DB::table('users')->where('role', 'super_admin')->where('id', '!=', $user->id)->pluck('id')->all();
            foreach ($superAdminIds as $saId) {
                $recipientIds[] = (int)$saId;
            }
            $emp = DB::table('employees')->where('id', $user->employee_id)->first();
            $empName = $emp ? trim($emp->first_name . ' ' . $emp->last_name) : $user->name;
            foreach (array_unique($recipientIds) as $recId) {
                DB::table('user_notifications')->insert([
                    'user_id' => $recId,
                    'event_key' => 'attendance.regularization_requested',
                    'title' => "Attendance Regularization: {$empName}",
                    'message' => "{$empName} requested attendance regularization for {$validated['date']} (Punch Out: {$validated['punch_out_requested']}). Reason: {$validated['reason']}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

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

            // Notify Employee
            $employeeUserId = DB::table('users')->where('employee_id', $regularization->employee_id)->value('id');
            if ($employeeUserId) {
                $statusText = $validated['status'] === 'approved' ? 'Approved' : 'Rejected';
                DB::table('user_notifications')->insert([
                    'user_id' => $employeeUserId,
                    'event_key' => 'attendance.regularization_reviewed',
                    'title' => "Attendance Regularization " . ucfirst($statusText),
                    'message' => "Your attendance regularization request for {$regularization->date} was {$validated['status']} by {$request->user()->name}." . ($validated['status'] === 'approved' ? " Working hours updated to {$workHours} hrs." : ""),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return response()->json(['message' => "Regularization {$validated['status']}"]);
    }

    public function getShiftSettings(): JsonResponse
    {
        $settings = DB::table('attendance_shift_settings')->orderBy('id', 'asc')->first();
        if (!$settings) {
            $id = DB::table('attendance_shift_settings')->insertGetId([
                'shift_name' => 'General Employee Shift',
                'start_time' => '09:30 AM',
                'end_time' => '06:30 PM',
                'grace_period_minutes' => 15,
                'late_mark_threshold' => 3,
                'auto_half_day_deduction' => true,
                'staff_shift_enabled' => true,
                'staff_shift_name' => 'Management & Staff Shift',
                'staff_start_time' => '10:00 AM',
                'staff_end_time' => '07:00 PM',
                'staff_grace_period_minutes' => 15,
                'staff_applied_roles' => json_encode(['manager', 'hr_admin', 'hr_executive', 'recruiter', 'payroll_admin', 'auditor']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $settings = DB::table('attendance_shift_settings')->where('id', $id)->first();
        }

        if ($settings && is_string($settings->staff_applied_roles)) {
            $settings->staff_applied_roles = json_decode($settings->staff_applied_roles, true) ?: [];
        }

        return response()->json(['data' => $settings]);
    }

    public function updateShiftSettings(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'super_admin', 403, 'Unauthorized. Only Super Admin can modify shift policies.');

        $validated = $request->validate([
            'shift_name' => 'sometimes|string|max:100',
            'start_time' => 'required|string',
            'end_time' => 'required|string',
            'grace_period_minutes' => 'required|integer|min:0|max:120',
            'full_day_min_hours' => 'nullable|numeric|min:0.5|max:24',
            'half_day_min_hours' => 'nullable|numeric|min:0.5|max:24',
            'late_mark_threshold' => 'required|integer|min:1|max:10',
            'auto_half_day_deduction' => 'required|boolean',
            'staff_shift_enabled' => 'sometimes|boolean',
            'staff_shift_name' => 'sometimes|string|max:100',
            'staff_start_time' => 'sometimes|string',
            'staff_end_time' => 'sometimes|string',
            'staff_grace_period_minutes' => 'sometimes|integer|min:0|max:120',
            'staff_applied_roles' => 'sometimes|array',
        ]);

        $updateData = [
            'shift_name' => $validated['shift_name'] ?? 'General Employee Shift',
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'],
            'grace_period_minutes' => $validated['grace_period_minutes'],
            'full_day_min_hours' => array_key_exists('full_day_min_hours', $validated) && $validated['full_day_min_hours'] !== '' ? $validated['full_day_min_hours'] : null,
            'half_day_min_hours' => array_key_exists('half_day_min_hours', $validated) && $validated['half_day_min_hours'] !== '' ? $validated['half_day_min_hours'] : null,
            'late_mark_threshold' => $validated['late_mark_threshold'],
            'auto_half_day_deduction' => $validated['auto_half_day_deduction'],
            'updated_at' => now(),
        ];

        if (array_key_exists('staff_shift_enabled', $validated)) {
            $updateData['staff_shift_enabled'] = $validated['staff_shift_enabled'];
        }
        if (array_key_exists('staff_shift_name', $validated)) {
            $updateData['staff_shift_name'] = $validated['staff_shift_name'];
        }
        if (array_key_exists('staff_start_time', $validated)) {
            $updateData['staff_start_time'] = $validated['staff_start_time'];
        }
        if (array_key_exists('staff_end_time', $validated)) {
            $updateData['staff_end_time'] = $validated['staff_end_time'];
        }
        if (array_key_exists('staff_grace_period_minutes', $validated)) {
            $updateData['staff_grace_period_minutes'] = $validated['staff_grace_period_minutes'];
        }
        if (array_key_exists('staff_applied_roles', $validated)) {
            $updateData['staff_applied_roles'] = json_encode($validated['staff_applied_roles']);
        }

        $first = DB::table('attendance_shift_settings')->orderBy('id', 'asc')->first();
        if ($first) {
            DB::table('attendance_shift_settings')->where('id', $first->id)->update($updateData);
            $updated = DB::table('attendance_shift_settings')->where('id', $first->id)->first();
        } else {
            $id = DB::table('attendance_shift_settings')->insertGetId(array_merge($updateData, ['created_at' => now()]));
            $updated = DB::table('attendance_shift_settings')->where('id', $id)->first();
        }

        if ($updated && is_string($updated->staff_applied_roles)) {
            $updated->staff_applied_roles = json_decode($updated->staff_applied_roles, true) ?: [];
        }

        AuditLog::record(
            $request,
            'ATTENDANCE_SHIFT_SETTINGS_UPDATED',
            'Attendance',
            "Updated shift timing: {$updated->start_time} - {$updated->end_time}, Grace: {$updated->grace_period_minutes}m, Late threshold: {$updated->late_mark_threshold}"
        );

        return response()->json([
            'message' => 'Attendance shift and grace period settings updated successfully',
            'data' => $updated
        ]);
    }
}
