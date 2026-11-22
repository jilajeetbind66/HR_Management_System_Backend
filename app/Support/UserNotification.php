<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class UserNotification
{
    /**
     * Send notification to a specific user.
     */
    public static function send(int $userId, string $eventKey, string $title, string $message): void
    {
        $now = now();
        DB::table('user_notifications')->insert([
            'user_id' => $userId,
            'event_key' => $eventKey,
            'title' => $title,
            'message' => $message,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Send notification to all users holding a specific role.
     */
    public static function sendToRole(string $role, string $eventKey, string $title, string $message, ?int $exceptUserId = null): void
    {
        $query = DB::table('users')->where('role', $role);
        if ($exceptUserId) {
            $query->where('id', '!=', $exceptUserId);
        }
        $users = $query->get(['id']);
        $now = now();
        $records = [];
        foreach ($users as $user) {
            $records[] = [
                'user_id' => $user->id,
                'event_key' => $eventKey,
                'title' => $title,
                'message' => $message,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if (!empty($records)) {
            DB::table('user_notifications')->insert($records);
        }
    }

    /**
     * Send notification to the user account linked to a specific employee.
     */
    public static function sendToEmployee(int $employeeId, string $eventKey, string $title, string $message): void
    {
        $user = DB::table('users')->where('employee_id', $employeeId)->first(['id']);
        if ($user) {
            self::send($user->id, $eventKey, $title, $message);
        }
    }

    /**
     * Send notification to the reporting manager of a specific employee.
     */
    public static function sendToManagerOf(int $employeeId, string $eventKey, string $title, string $message): void
    {
        $employee = DB::table('employees')->where('id', $employeeId)->first(['reporting_manager_id']);
        if ($employee && $employee->reporting_manager_id) {
            $managerUser = DB::table('users')->where('employee_id', $employee->reporting_manager_id)->first(['id']);
            if ($managerUser) {
                self::send($managerUser->id, $eventKey, $title, $message);
            }
        }
    }

    /**
     * Get employee full name.
     */
    public static function employeeName(int $employeeId): string
    {
        $emp = DB::table('employees')->where('id', $employeeId)->first(['first_name', 'last_name']);
        if (!$emp) {
            return "Employee #{$employeeId}";
        }
        return trim($emp->first_name . ' ' . $emp->last_name);
    }
}
