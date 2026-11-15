<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RoleAccess
{
    public static function isHr(string $role): bool
    {
        return in_array($role, ['super_admin', 'hr_admin', 'hr_executive'], true);
    }

    public static function canViewAllEmployees(string $role): bool
    {
        return in_array($role, ['super_admin', 'hr_admin', 'hr_executive', 'auditor'], true);
    }

    public static function visibleEmployeeIds(User $user, bool $includeSelf = true): array
    {
        if (!$user->employee_id) {
            return [];
        }

        $employeeIds = $user->role === 'manager'
            ? DB::table('employees')
                ->where('reporting_manager_id', $user->employee_id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all()
            : [];

        if ($includeSelf) {
            $employeeIds[] = (int) $user->employee_id;
        }

        return array_values(array_unique($employeeIds));
    }

    public static function managesEmployee(User $user, int $employeeId): bool
    {
        return $user->role === 'manager'
            && $user->employee_id
            && DB::table('employees')
                ->where('id', $employeeId)
                ->where('reporting_manager_id', $user->employee_id)
                ->exists();
    }

    public static function canManageAssignment(User $user, object $assignment): bool
    {
        if ($user->role === 'super_admin') {
            return true;
        }

        if ($user->role === 'manager') {
            return (int) $assignment->employee_id === (int) $user->employee_id
                || self::managesEmployee($user, (int) $assignment->employee_id);
        }

        return (int) $assignment->employee_id === (int) $user->employee_id;
    }
}
