<!DOCTYPE html>
<html lang="en">
<body>
    <h1>Verify your new HRMS login email</h1>
    <p>Use the link below to confirm this email address for your HRMS account.</p>
    <p><a href="{{ $verificationUrl }}">Verify email address</a></p>
    <p>This link expires at {{ $expiresAt }} UTC and can only be used once.</p>
    <p>If you did not request this change, you can ignore this message.</p>
</body>
</html>
