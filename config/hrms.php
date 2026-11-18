<?php

return [
    'allow_cross_branch_managers' => env('HRMS_ALLOW_CROSS_BRANCH_MANAGERS', false),
    'minimum_work_hours' => env('HRMS_MINIMUM_WORK_HOURS', 4),
    'below_minimum_attendance_status' => env('HRMS_BELOW_MINIMUM_STATUS', 'Short Hours'),
];
