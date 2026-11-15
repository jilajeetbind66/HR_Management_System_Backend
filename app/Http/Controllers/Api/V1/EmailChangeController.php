<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\EmailChangeVerification;
use App\Models\User;
use App\Support\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class EmailChangeController extends Controller
{
    private const TOKEN_LIFETIME_MINUTES = 60;

    public function requestOwnEmailChange(Request $request): JsonResponse
    {
        return $this->sendVerification($request, $request->user());
    }

    public function requestForUser(Request $request, User $user): JsonResponse
    {
        return $this->sendVerification($request, $user);
    }

    public function confirm(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'size:64', 'regex:/\A[a-f0-9]{64}\z/'],
        ]);

        $result = DB::transaction(function () use ($request, $validated): array {
            $emailChange = DB::table('email_change_tokens')
                ->where('token_hash', hash('sha256', $validated['token']))
                ->lockForUpdate()
                ->first();

            if (!$emailChange || now()->greaterThanOrEqualTo($emailChange->expires_at)) {
                return ['status' => 'expired'];
            }

            $user = User::whereKey($emailChange->user_id)->lockForUpdate()->first();
            $requester = User::whereKey($emailChange->requested_by_user_id)->first();

            if (!$user || !$requester || ($user->id !== $requester->id && $requester->role !== 'super_admin')) {
                return ['status' => 'unauthorized'];
            }

            if (User::where('email', $emailChange->new_email)->where('id', '<>', $user->id)->exists()) {
                DB::table('email_change_tokens')->where('user_id', $user->id)->delete();
                return ['status' => 'email_taken'];
            }

            $user->email = $emailChange->new_email;
            $user->email_verified_at = now();
            $user->save();
            $user->tokens()->delete();

            DB::table('email_change_tokens')->where('user_id', $user->id)->delete();
            DB::table('audit_logs')->insert([
                'action' => 'USER_EMAIL_UPDATED',
                'actor' => $requester->name,
                'module' => 'Access Management',
                'description' => "Verified login email for user #{$user->id}",
                'ip' => $request->ip(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return ['status' => 'confirmed', 'target_user_id' => $user->id];
        });

        if ($result['status'] === 'expired') {
            return response()->json(['message' => 'This verification link is invalid, expired, or already used.'], 410);
        }

        if ($result['status'] === 'unauthorized') {
            return response()->json(['message' => 'The account that requested this change is no longer authorized.'], 403);
        }

        if ($result['status'] === 'email_taken') {
            return response()->json(['message' => 'This email address is no longer available. Request a new change using another address.'], 409);
        }

        return response()->json([
            'message' => 'Login email verified. Sign in again using the new email address.',
            'data' => ['target_user_id' => $result['target_user_id']],
        ]);
    }

    private function sendVerification(Request $request, User $target): JsonResponse
    {
        if (config('mail.default') === 'log') {
            return response()->json([
                'message' => 'Email delivery is not configured. Configure an SMTP or supported mail transport before changing login emails.',
            ], 503);
        }

        DB::table('email_change_tokens')->where('expires_at', '<=', now())->delete();

        $email = $request->input('email');
        $emailConfirmation = $request->input('email_confirmation');
        $request->merge([
            'email' => is_string($email) ? strtolower(trim($email)) : $email,
            'email_confirmation' => is_string($emailConfirmation)
                ? strtolower(trim($emailConfirmation))
                : $emailConfirmation,
        ]);

        $validated = $request->validate([
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($target->id),
                Rule::unique('email_change_tokens', 'new_email'),
            ],
            'email_confirmation' => ['required', 'same:email'],
            'current_password' => ['required', 'string'],
        ]);

        if (!Hash::check($validated['current_password'], $request->user()->password)) {
            return response()->json([
                'message' => 'The current password is incorrect.',
                'errors' => ['current_password' => ['The current password is incorrect.']],
            ], 422);
        }

        if ($validated['email'] === strtolower($target->email)) {
            return response()->json([
                'message' => 'The new email must be different from the current login email.',
                'errors' => ['email' => ['The new email must be different from the current login email.']],
            ], 422);
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = now()->addMinutes(self::TOKEN_LIFETIME_MINUTES);

        DB::transaction(function () use ($request, $target, $validated, $token, $expiresAt): void {
            DB::table('email_change_tokens')->where('user_id', $target->id)->delete();
            DB::table('email_change_tokens')->insert([
                'user_id' => $target->id,
                'requested_by_user_id' => $request->user()->id,
                'new_email' => $validated['email'],
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $verificationUrl = rtrim(config('app.frontend_url'), '/')
            . '/verify-email-change?token=' . rawurlencode($token);

        try {
            Mail::to($validated['email'])->send(new EmailChangeVerification(
                $verificationUrl,
                $expiresAt->toIso8601String()
            ));
        } catch (TransportExceptionInterface $exception) {
            DB::table('email_change_tokens')->where('token_hash', hash('sha256', $token))->delete();
            report($exception);

            return response()->json([
                'message' => 'The verification email could not be sent. Check the email transport configuration and try again.',
            ], 503);
        }

        AuditLog::record(
            $request,
            'USER_EMAIL_CHANGE_REQUESTED',
            'Access Management',
            "Requested verified login email change for user #{$target->id}"
        );

        return response()->json([
            'message' => 'A verification link has been sent to the new email address. The login email will change after confirmation.',
        ], 202);
    }
}
