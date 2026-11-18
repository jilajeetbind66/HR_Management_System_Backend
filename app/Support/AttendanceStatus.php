<?php

namespace App\Support;

use LogicException;

final class AttendanceStatus
{
    public static function forWorkHours(float $workHours): string
    {
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
