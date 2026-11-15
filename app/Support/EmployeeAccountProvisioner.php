<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

final class EmployeeAccountProvisioner
{
    private const ASSIGNABLE_ROLES = [
        'employee',
        'manager',
        'hr_admin',
        'hr_executive',
        'recruiter',
        'payroll_admin',
        'auditor',
    ];

    public static function assignableRoles(): array
    {
        return self::ASSIGNABLE_ROLES;
    }

    public function provision(
        int $employeeId,
        string $name,
        string $email,
        string $role = 'employee'
    ): string
    {
        if (!in_array($role, self::ASSIGNABLE_ROLES, true)) {
            throw new \InvalidArgumentException('The selected role is not available for employee account creation.');
        }

        $temporaryPassword = Str::random(24);
        $user = new User();
        $user->name = $name;
        $user->email = $email;
        $user->password = $temporaryPassword;
        $user->employee_id = $employeeId;
        $user->role = $role;
        $user->must_change_password = false;
        $user->save();

        return $temporaryPassword;
    }
}
