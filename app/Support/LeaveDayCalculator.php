<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

final class LeaveDayCalculator
{
    public static function calculate(
        int $employeeId,
        string $fromDate,
        string $toDate,
        bool $halfDay
    ): array {
        $start = Carbon::parse($fromDate)->startOfDay();
        $end = Carbon::parse($toDate)->startOfDay();
        if ($start->diffInDays($end) > 366) {
            throw ValidationException::withMessages([
                'to_date' => 'A leave request cannot span more than one year.',
            ]);
        }

        $branchId = DB::table('employees')->where('id', $employeeId)->value('branch_id');
        $holidayRows = DB::table('holidays')
            ->whereBetween('date', [$fromDate, $toDate])
            ->get(['date', 'branch_ids']);
        $holidays = [];
        foreach ($holidayRows as $holiday) {
            if ($holiday->branch_ids === null || $holiday->branch_ids === '') {
                $holidays[$holiday->date] = true;
                continue;
            }
            $branchIds = json_decode($holiday->branch_ids, true);
            if (is_array($branchIds) && in_array((int) $branchId, array_map('intval', $branchIds), true)) {
                $holidays[$holiday->date] = true;
            }
        }

        $dates = [];
        for ($date = $start->copy(); $date->lessThanOrEqualTo($end); $date->addDay()) {
            $key = $date->toDateString();
            $dates[] = ['date' => $key, 'non_working' => $date->isWeekend() || isset($holidays[$key])];
        }

        if ($halfDay) {
            if ($start->toDateString() !== $end->toDateString()) {
                throw ValidationException::withMessages([
                    'is_half_day' => 'Half-day leave must be requested for a single date.',
                ]);
            }
            if ($dates[0]['non_working']) {
                throw ValidationException::withMessages([
                    'from_date' => 'Half-day leave must fall on a working day.',
                ]);
            }

            return ['days' => 0.5, 'excluded_dates' => [], 'included_dates' => [$dates[0]['date']]];
        }

        $days = 0;
        $includedDates = [];
        for ($index = 0, $count = count($dates); $index < $count; $index++) {
            if (!$dates[$index]['non_working']) {
                $days++;
                $includedDates[] = $dates[$index]['date'];
                continue;
            }

            $runEnd = $index;
            while ($runEnd + 1 < $count && $dates[$runEnd + 1]['non_working']) {
                $runEnd++;
            }
            if ($index > 0 && $runEnd < $count - 1) {
                for ($includedIndex = $index; $includedIndex <= $runEnd; $includedIndex++) {
                    $days++;
                    $includedDates[] = $dates[$includedIndex]['date'];
                }
            }
            $index = $runEnd;
        }
        if ($days < 0.5) {
            throw ValidationException::withMessages([
                'from_date' => 'The selected range contains no eligible working leave days.',
            ]);
        }

        return [
            'days' => (float) $days,
            'excluded_dates' => array_values(array_diff(array_column($dates, 'date'), $includedDates)),
            'included_dates' => $includedDates,
        ];
    }
}
