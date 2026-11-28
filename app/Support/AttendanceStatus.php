<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;

final class AttendanceStatus
{
    public static function calculateShiftDutyHours(?object $shift, ?string $role = null): float
    {
        if (!$shift) {
            return 7.0;
        }

        $appliedRoles = is_string($shift->staff_applied_roles ?? null)
            ? (json_decode($shift->staff_applied_roles, true) ?: [])
            : (is_array($shift->staff_applied_roles ?? null) ? $shift->staff_applied_roles : []);

        $isStaffShift = !empty($shift->staff_shift_enabled)
            && $role
            && in_array($role, $appliedRoles, true);

        $startStr = $isStaffShift ? ($shift->staff_start_time ?? '10:00 AM') : ($shift->start_time ?? '09:30 AM');
        $endStr = $isStaffShift ? ($shift->staff_end_time ?? '07:00 PM') : ($shift->end_time ?? '06:30 PM');

        try {
            $start = Carbon::parse($startStr);
            $end = Carbon::parse($endStr);
            if ($end->lessThanOrEqualTo($start)) {
                $end->addDay();
            }

            return round($start->diffInMinutes($end) / 60, 2);
        } catch (\Throwable) {
            return 7.0;
        }
    }

    public static function forWorkHours(float $workHours, ?string $role = null): string
    {
        $shift = null;
        try {
            $shift = DB::table('attendance_shift_settings')->orderBy('id', 'asc')->first();
        } catch (\Throwable) {
            $shift = null;
        }

        if ($shift) {
            $dutyHours = self::calculateShiftDutyHours($shift, $role);
            $fullDayMin = is_numeric($shift->full_day_min_hours ?? null)
                ? (float) $shift->full_day_min_hours
                : max(1.0, round($dutyHours - 0.5, 2));
            $halfDayMin = is_numeric($shift->half_day_min_hours ?? null)
                ? (float) $shift->half_day_min_hours
                : round($dutyHours / 2, 2);

            if ($workHours >= $fullDayMin) {
                return 'Present';
            }
            if ($workHours >= $halfDayMin) {
                return 'Half Day';
            }

            return config('hrms.below_minimum_attendance_status', 'Short Hours');
        }

        $minimumHours = config('hrms.minimum_work_hours');
        if (!is_numeric($minimumHours) || (float) $minimumHours <= 0 || (float) $minimumHours > 24) {
            throw new LogicException('HRMS_MINIMUM_WORK_HOURS must be greater than 0 and no greater than 24.');
        }

        $shortHoursStatus = config('hrms.below_minimum_attendance_status');
        if (!in_array($shortHoursStatus, ['Half Day', 'Short Hours'], true)) {
            throw new LogicException('HRMS_BELOW_MINIMUM_STATUS must be Half Day or Short Hours.');
        }

        return $workHours < (float) $minimumHours ? $shortHoursStatus : 'Present';
    }
}
