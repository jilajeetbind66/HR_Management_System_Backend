<?php

namespace App\Support;

use App\Mail\EmployeeAccountSetup;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

final class EmployeeAccountSetupNotifier
{
    public function send(User $user, string $employeeCode, string $role): void
    {
        $user->forceFill(['must_change_password' => true])->save();

        $setupToken = Password::broker()->createToken($user);
        $setupUrl = rtrim(config('app.frontend_url'), '/')
            . '/setup-password?email=' . rawurlencode($user->email)
            . '&token=' . rawurlencode($setupToken);

        Mail::to($user->email)->send(new EmployeeAccountSetup(
            employeeName: $user->name,
            loginEmail: $user->email,
            employeeCode: $employeeCode,
            role: ucwords(str_replace('_', ' ', $role)),
            setupUrl: $setupUrl,
            expiresInMinutes: (int) config('auth.passwords.users.expire')
        ));
    }
}
