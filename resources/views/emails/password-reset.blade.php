<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset your Pinweb HRMS password</title>
</head>
<body style="margin:0;padding:0;background:#f0fdf4;color:#0f172a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;line-height:1.5;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f0fdf4;padding:40px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width:560px;background:#ffffff;border:1px solid #d1fae5;border-radius:16px;overflow:hidden;box-shadow:0 10px 25px -5px rgba(5,150,105,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="padding:28px 32px;background:linear-gradient(135deg,#065f46 0%,#047857 50%,#059669 100%);color:#ffffff;">
                            <table role="presentation" width="100%">
                                <tr>
                                    <td>
                                        <h1 style="margin:0;font-size:22px;font-weight:800;letter-spacing:-0.5px;color:#ffffff;">Pinweb HRMS</h1>
                                        <p style="margin:4px 0 0;font-size:13px;color:#a7f3d0;">Enterprise Workforce Management</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding:32px;">
                            <h2 style="margin:0 0 16px;font-size:18px;font-weight:700;color:#0f172a;">Password Reset Request</h2>
                            
                            <p style="margin:0 0 12px;font-size:14px;color:#334155;">Hello <strong>{{ $name }}</strong>,</p>
                            <p style="margin:0 0 20px;font-size:14px;color:#475569;line-height:1.6;">
                                We received a request to reset the password for your account associated with <strong>{{ $email }}</strong>. Click the button below to choose a new secure password:
                            </p>
                            
                            <!-- Action Button -->
                            <div style="text-align:center;margin:28px 0;">
                                <a href="{{ $resetUrl }}" style="display:inline-block;padding:13px 28px;background:#059669;color:#ffffff;text-decoration:none;border-radius:10px;font-weight:700;font-size:14px;box-shadow:0 4px 12px rgba(5,150,105,0.35);">
                                    Reset Password
                                </a>
                            </div>

                            <!-- Expiration notice -->
                            <div style="background:#f8fafc;border-left:4px solid #10b981;border-radius:6px;padding:12px 16px;margin:24px 0;">
                                <p style="margin:0;font-size:13px;color:#475569;">
                                    <strong>Security Notice:</strong> This reset link will expire in <strong>{{ $expiresInMinutes }} minutes</strong> and can only be used once.
                                </p>
                            </div>

                            <!-- Link fallback -->
                            <p style="margin:20px 0 6px;font-size:12px;color:#64748b;">
                                If you're having trouble clicking the "Reset Password" button, copy and paste the URL below into your web browser:
                            </p>
                            <p style="margin:0 0 24px;font-size:11px;color:#059669;word-break:break-all;">
                                <a href="{{ $resetUrl }}" style="color:#059669;text-decoration:underline;">{{ $resetUrl }}</a>
                            </p>

                            <hr style="border:none;border-top:1px solid #e2e8f0;margin:24px 0 16px;" />

                            <p style="margin:0;font-size:12px;color:#94a3b8;line-height:1.5;">
                                If you did not request this password reset, no further action is needed. Your account remains secure. If you suspect unauthorized activity, please contact your HR or IT administrator immediately.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding:16px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;">
                            <p style="margin:0;font-size:11px;color:#94a3b8;">
                                &copy; {{ date('Y') }} Pinweb Private Limited. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
