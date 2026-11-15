<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $timestamp = now();

        DB::table('employees')
            ->leftJoin('leave_balances', 'employees.id', '=', 'leave_balances.employee_id')
            ->whereIn('employees.status', ['active', 'on_notice'])
            ->whereNull('leave_balances.employee_id')
            ->select('employees.id')
            ->orderBy('employees.id')
            ->chunkById(500, function ($employees) use ($timestamp): void {
                $balances = $employees->map(static fn ($employee): array => [
                    'employee_id' => $employee->id,
                    'cl' => 12.00,
                    'sl' => 12.00,
                    'pl' => 15.00,
                    'comp_off' => 0.00,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ])->all();

                DB::table('leave_balances')->insertOrIgnore($balances);
            }, 'employees.id', 'id');
    }

    public function down(): void
    {
        // Backfilled balances may have been used or adjusted, so rollback must preserve them.
    }
};
