<?php

namespace App\Support;

use Carbon\Carbon;

final class ProbationStatus
{
    private const PROBATION_MONTHS = 6;

    public static function fromJoiningDate(string $joiningDate): string
    {
        $confirmationDate = Carbon::parse($joiningDate)
            ->startOfDay()
            ->addMonthsNoOverflow(self::PROBATION_MONTHS);

        return now()->startOfDay()->lt($confirmationDate)
            ? 'Probation (6 Mo)'
            : 'Confirmed';
    }
}
