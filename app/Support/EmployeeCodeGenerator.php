<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class EmployeeCodeGenerator
{
    public function next(): string
    {
        $number = DB::table('employees')->count() + 1;
        do {
            $code = 'EMP-' . str_pad((string) $number++, 3, '0', STR_PAD_LEFT);
        } while (DB::table('employees')->where('employee_code', $code)->exists());

        return $code;
    }
}
