<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use App\Support\AuditLog;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'identifier' => 'required|string|max:255',
            'password' => 'required|string',
            'role_type' => 'nullable|in:employee,manager,hr,admin',
        ]);

        $identifier = trim($validated['identifier']);
        $user = User::where('email', strtolower($identifier))->first();

        if (!$user) {
            $employee = DB::table('employees')
                ->where('employee_code', strtoupper($identifier))
                ->first(['id']);
            $user = $employee ? User::where('employee_id', $employee->id)->first() : null;
        }

        $roleGroups = [
            'employee' => ['employee'],
            'manager' => ['manager'],
            'hr' => ['hr_admin', 'hr_executive', 'recruiter'],
            'admin' => ['super_admin', 'payroll_admin', 'auditor'],
        ];
        $roleMatches = !isset($validated['role_type'])
            || in_array($user?->role, $roleGroups[$validated['role_type']] ?? [], true);

        if (!$user || !$roleMatches || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'The provided credentials are invalid.',
                'code' => 'INVALID_CREDENTIALS',
            ], 401);
        }

        $user->tokens()->delete();
        $token = $user->createToken('hrms-web', ['*'], now()->addHours(8))->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'user' => $this->userPayload($user),
            ],
        ]);
    }

    public function current(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->userPayload($request->user()),
        ]);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:12', 'max:255', 'confirmed', 'different:current_password'],
        ]);
        $user = $request->user();

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'message' => 'The current password is incorrect.',
                'errors' => ['current_password' => ['The current password is incorrect.']],
            ], 422);
        }

        DB::transaction(function () use ($request, $user, $validated): void {
            $user->password = $validated['password'];
            $user->must_change_password = false;
            $user->save();

            $currentToken = $user->currentAccessToken();
            if ($currentToken instanceof PersonalAccessToken) {
                $user->tokens()->where('id', '!=', $currentToken->getKey())->delete();
            }

            AuditLog::record(
                $request,
                'USER_PASSWORD_CHANGED',
                'Access Management',
                "Changed password for user #{$user->id}"
            );
        });

        return response()->json([
            'message' => 'Password changed successfully.',
            'data' => $this->userPayload($user->fresh()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Logged out successfully.']);
    }

    private function userPayload(User $user): array
    {
        $employee = $user->employee_id
            ? DB::table('employees')->where('id', $user->employee_id)->first()
            : null;
        $nameParts = explode(' ', trim($user->name), 2);

        return [
            'id' => $user->employee_id ?? $user->id,
            'user_id' => $user->id,
            'employee_id' => $user->employee_id,
            'name' => $user->name,
            'first_name' => $employee->first_name ?? $nameParts[0] ?? '',
            'last_name' => $employee->last_name ?? $nameParts[1] ?? '',
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'role' => $user->role,
            'must_change_password' => (bool) $user->must_change_password,
            'employee_code' => $employee->employee_code ?? null,
            'designation' => $employee->designation ?? null,
            'branch_name' => $employee
                ? DB::table('branches')->where('id', $employee->branch_id)->value('name')
                : null,
            'avatar' => $employee->avatar ?? null,
        ];
    }
}
