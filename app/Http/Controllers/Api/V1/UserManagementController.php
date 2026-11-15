<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class UserManagementController extends Controller
{
    public function index(): JsonResponse
    {
        $users = DB::table('users')
            ->leftJoin('employees', 'employees.id', '=', 'users.employee_id')
            ->orderBy('users.name')
            ->get([
                'users.id',
                'users.name',
                'users.email',
                'users.role',
                'users.must_change_password',
                'employees.employee_code',
            ]);

        return response()->json(['data' => $users]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed'],
        ]);

        DB::transaction(function () use ($request, $user, $validated): void {
            $user->password = $validated['password'];
            $user->save();

            $this->revokeSessions($user, $request->user());

            AuditLog::record(
                $request,
                'USER_PASSWORD_RESET',
                'Access Management',
                "Reset password for user #{$user->id}"
            );
        });

        return response()->json(['message' => 'Password reset successfully.']);
    }

    private function revokeSessions(User $target, User $actor): void
    {
        $currentToken = $actor->currentAccessToken();
        if ($target->is($actor) && $currentToken instanceof PersonalAccessToken) {
            $target->tokens()->where('id', '!=', $currentToken->getKey())->delete();
            return;
        }

        $target->tokens()->delete();
    }
}
