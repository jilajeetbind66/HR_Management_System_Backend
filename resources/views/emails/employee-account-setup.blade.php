<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Set up your Pinweb HRMS account</title>
</head>
<body style="margin:0;background:#f1f5f9;color:#0f172a;font-family:Arial,sans-serif;">
    <main style="max-width:560px;margin:32px auto;padding:28px;background:#fff;border:1px solid #e2e8f0;border-radius:16px;">
        <h1 style="margin:0 0 16px;font-size:22px;">Welcome to Pinweb HRMS</h1>
        <p>Hello {{ $employeeName }},</p>
        <p>Your employee account is ready. Use the details below to identify your account:</p>
        <ul>
            <li>Login email: <strong>{{ $loginEmail }}</strong></li>
            <li>Employee code: <strong>{{ $employeeCode }}</strong></li>
            <li>Role: <strong>{{ $role }}</strong></li>
        </ul>
        <p>Choose your own password using this one-time link:</p>
        <p style="margin:24px 0;">
            <a href="{{ $setupUrl }}" style="display:inline-block;padding:12px 18px;background:#0f9488;color:#fff;text-decoration:none;border-radius:8px;font-weight:bold;">
                Set up password
            </a>
        </p>
        <p>This link expires in {{ $expiresInMinutes }} minutes and can only be used once. If you did not expect this email, contact your HR team.</p>
    </main>
</body>
</html>
