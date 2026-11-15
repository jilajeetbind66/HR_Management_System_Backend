<?php

namespace Tests\Feature;

use App\Models\User;
use App\Mail\EmailChangeVerification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('hrms:install', ['--force' => true]);
    }

    public function test_super_admin_can_list_users_without_exposing_passwords(): void
    {
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());

        $response = $this->getJson('/api/v1/admin/users')->assertOk();
        $users = $response->json('data');

        $this->assertNotEmpty($users);
        foreach ($users as $user) {
            $this->assertArrayNotHasKey('password', $user);
            $this->assertArrayNotHasKey('password_confirmation', $user);
        }
    }

    public function test_super_admin_can_reset_a_password_and_revoke_target_sessions(): void
    {
        $admin = User::where('role', 'super_admin')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        $employee->createToken('existing-session');
        Sanctum::actingAs($admin);

        $newPassword = 'NewEmployeePassword!2026';
        $this->patchJson("/api/v1/admin/users/{$employee->id}/password", [
            'password' => $newPassword,
            'password_confirmation' => $newPassword,
        ])->assertOk()
            ->assertJson(['message' => 'Password reset successfully.']);

        $employee->refresh();
        $this->assertTrue(Hash::check($newPassword, $employee->password));
        $this->assertSame(0, $employee->tokens()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'USER_PASSWORD_RESET',
            'module' => 'Access Management',
            'actor' => $admin->name,
            'description' => "Reset password for user #{$employee->id}",
        ]);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', [
            'identifier' => $employee->email,
            'password' => $newPassword,
            'role_type' => 'employee',
        ])->assertOk();
    }

    public function test_super_admin_can_reset_their_password_without_ending_the_current_session(): void
    {
        $admin = User::where('role', 'super_admin')->firstOrFail();
        $currentSession = $admin->createToken('current-session');
        $otherSession = $admin->createToken('other-session');
        $newPassword = 'NewAdminPassword!2026';

        $this->withToken($currentSession->plainTextToken)
            ->patchJson("/api/v1/admin/users/{$admin->id}/password", [
                'password' => $newPassword,
                'password_confirmation' => $newPassword,
            ])->assertOk();

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $currentSession->accessToken->id,
        ]);
        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $otherSession->accessToken->id,
        ]);
        $this->assertTrue(Hash::check($newPassword, $admin->fresh()->password));

        $this->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_super_admin_can_change_login_email_and_revoke_target_sessions(): void
    {
        $admin = User::where('role', 'super_admin')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        $oldEmail = $employee->email;
        $employee->createToken('existing-session');
        Mail::fake();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/users/{$employee->id}/email", [
            'email' => 'EMPLOYEE.NEW@EXAMPLE.COM',
            'email_confirmation' => 'employee.new@example.com',
            'current_password' => 'AdminPass@2026#Secure',
        ])->assertAccepted()
            ->assertJsonPath('message', 'A verification link has been sent to the new email address. The login email will change after confirmation.');

        $this->assertSame($oldEmail, $employee->fresh()->email);
        $this->assertSame(1, $employee->tokens()->count());
        $token = $this->verificationTokenFor('employee.new@example.com');

        $this->postJson('/api/v1/auth/email-change-confirm', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.target_user_id', $employee->id);

        $employee->refresh();
        $this->assertSame('employee.new@example.com', $employee->email);
        $this->assertNotNull($employee->email_verified_at);
        $this->assertSame(0, $employee->tokens()->count());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'USER_EMAIL_UPDATED',
            'module' => 'Access Management',
            'actor' => $admin->name,
            'description' => "Verified login email for user #{$employee->id}",
        ]);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', [
            'identifier' => 'employee.new@example.com',
            'password' => 'AdminPass@2026#Secure',
            'role_type' => 'employee',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'identifier' => $oldEmail,
            'password' => 'AdminPass@2026#Secure',
            'role_type' => 'employee',
        ])->assertUnauthorized();
    }

    public function test_non_super_admin_cannot_list_or_reset_user_passwords(): void
    {
        $auditor = User::where('role', 'auditor')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        Sanctum::actingAs($auditor);

        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->patchJson("/api/v1/admin/users/{$employee->id}/password", [
            'password' => 'NewEmployeePassword!2026',
            'password_confirmation' => 'NewEmployeePassword!2026',
        ])->assertForbidden();
        $this->patchJson("/api/v1/admin/users/{$employee->id}/email", [
            'email' => 'employee.new@example.com',
            'email_confirmation' => 'employee.new@example.com',
            'current_password' => 'AdminPass@2026#Secure',
        ])->assertForbidden();
    }

    public function test_password_reset_requires_a_confirmed_password_of_at_least_twelve_characters(): void
    {
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $employee = User::where('role', 'employee')->firstOrFail();
        $originalHash = $employee->password;

        $this->patchJson("/api/v1/admin/users/{$employee->id}/password", [
            'password' => 'short-pass',
            'password_confirmation' => 'short-pass',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->patchJson("/api/v1/admin/users/{$employee->id}/password", [
            'password' => 'LongEnoughPassword!2026',
            'password_confirmation' => 'DifferentPassword!2026',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->assertSame($originalHash, $employee->fresh()->password);
    }

    public function test_email_change_requires_a_unique_confirmed_email_address(): void
    {
        Sanctum::actingAs(User::where('role', 'super_admin')->firstOrFail());
        $employee = User::where('role', 'employee')->firstOrFail();
        $originalEmail = $employee->email;
        $existingEmail = User::where('role', 'super_admin')->value('email');

        $this->patchJson("/api/v1/admin/users/{$employee->id}/email", [
            'email' => strtoupper($existingEmail),
            'email_confirmation' => strtolower($existingEmail),
            'current_password' => 'AdminPass@2026#Secure',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->patchJson("/api/v1/admin/users/{$employee->id}/email", [
            'email' => 'employee.new@example.com',
            'email_confirmation' => 'another.email@example.com',
            'current_password' => 'AdminPass@2026#Secure',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email_confirmation');

        $this->assertSame($originalEmail, $employee->fresh()->email);
    }

    public function test_email_change_confirmation_is_single_use_and_expiring(): void
    {
        $admin = User::where('role', 'super_admin')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        Mail::fake();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/users/{$employee->id}/email", [
            'email' => 'employee.expiring@example.com',
            'email_confirmation' => 'employee.expiring@example.com',
            'current_password' => 'AdminPass@2026#Secure',
        ])->assertAccepted();

        $token = $this->verificationTokenFor('employee.expiring@example.com');
        $emailChangeToken = \Illuminate\Support\Facades\DB::table('email_change_tokens')
            ->where('user_id', $employee->id)
            ->first();
        $this->assertNotNull($emailChangeToken);

        \Illuminate\Support\Facades\DB::table('email_change_tokens')
            ->where('id', $emailChangeToken->id)
            ->update(['expires_at' => now()->subMinute()]);

        $this->postJson('/api/v1/auth/email-change-confirm', ['token' => $token])
            ->assertStatus(410);
        $this->assertSame($employee->email, $employee->fresh()->email);
    }

    public function test_user_can_change_their_own_login_email_only_after_verification(): void
    {
        $employee = User::where('role', 'employee')->firstOrFail();
        $oldEmail = $employee->email;
        $employeeSession = $employee->createToken('email-change-session');
        Mail::fake();

        $this->withToken($employeeSession->plainTextToken)
            ->postJson('/api/v1/auth/email-change-request', [
                'email' => 'employee.self@example.com',
                'email_confirmation' => 'employee.self@example.com',
                'current_password' => 'incorrect-current-password',
            ])->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertSame($oldEmail, $employee->fresh()->email);
        $this->assertDatabaseCount('email_change_tokens', 0);

        $this->withToken($employeeSession->plainTextToken)
            ->postJson('/api/v1/auth/email-change-request', [
                'email' => 'employee.self@example.com',
                'email_confirmation' => 'employee.self@example.com',
                'current_password' => 'AdminPass@2026#Secure',
            ])->assertAccepted();

        $token = $this->verificationTokenFor('employee.self@example.com');
        $this->postJson('/api/v1/auth/email-change-confirm', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('data.target_user_id', $employee->id);

        $this->assertSame('employee.self@example.com', $employee->fresh()->email);
        $this->assertNotNull($employee->fresh()->email_verified_at);
        $this->assertSame(0, $employee->tokens()->count());
        $this->assertSame($oldEmail, DB::table('employees')->where('id', $employee->employee_id)->value('email'));
        $this->postJson('/api/v1/auth/email-change-confirm', ['token' => $token])
            ->assertStatus(410);
    }

    public function test_email_change_requires_a_delivering_mail_transport(): void
    {
        config(['mail.default' => 'log']);
        $admin = User::where('role', 'super_admin')->firstOrFail();
        $employee = User::where('role', 'employee')->firstOrFail();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/v1/admin/users/{$employee->id}/email", [
            'email' => 'employee.no-mail@example.com',
            'email_confirmation' => 'employee.no-mail@example.com',
            'current_password' => 'AdminPass@2026#Secure',
        ])->assertStatus(503)
            ->assertJsonPath('message', 'Email delivery is not configured. Configure an SMTP or supported mail transport before changing login emails.');

        $this->assertDatabaseCount('email_change_tokens', 0);
        $this->assertSame($employee->email, $employee->fresh()->email);
    }

    private function verificationTokenFor(string $email): string
    {
        $token = null;
        Mail::assertSent(EmailChangeVerification::class, function (EmailChangeVerification $mail) use ($email, &$token): bool {
            if (!$mail->hasTo($email)) {
                return false;
            }

            parse_str((string) parse_url($mail->verificationUrl, PHP_URL_QUERY), $query);
            $token = $query['token'] ?? null;
            return is_string($token);
        });

        $this->assertNotNull($token);
        return $token;
    }
}
