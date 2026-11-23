<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HRMS Access Request</title>
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
                                        <p style="margin:4px 0 0;font-size:13px;color:#a7f3d0;">Enterprise Workforce Management &bull; Support</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    
                    <!-- Content -->
                    <tr>
                        <td style="padding:32px;">
                            <h2 style="margin:0 0 16px;font-size:18px;font-weight:700;color:#0f172a;">New HRMS Access Request</h2>
                            
                            <p style="margin:0 0 16px;font-size:14px;color:#475569;line-height:1.6;">
                                An employee or applicant has submitted an access request directly from the HRMS Login Page. Details are provided below:
                            </p>
                            
                            <!-- Requester Details Card -->
                            <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin-bottom:20px;">
                                <table role="presentation" width="100%" style="font-size:13px;color:#334155;">
                                    <tr>
                                        <td style="padding:6px 0;width:140px;color:#64748b;font-weight:600;">Requester Name:</td>
                                        <td style="padding:6px 0;font-weight:700;color:#0f172a;">{{ $name }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:6px 0;color:#64748b;font-weight:600;">Email Address:</td>
                                        <td style="padding:6px 0;font-weight:700;color:#059669;">
                                            <a href="mailto:{{ $email }}" style="color:#059669;text-decoration:none;">{{ $email }}</a>
                                        </td>
                                    </tr>
                                    <tr>
                                        <td style="padding:6px 0;color:#64748b;font-weight:600;">Employee Code / Phone:</td>
                                        <td style="padding:6px 0;font-weight:600;color:#0f172a;">{{ $employeeCode }}</td>
                                    </tr>
                                    @if(!empty($ipAddress))
                                    <tr>
                                        <td style="padding:6px 0;color:#64748b;font-weight:600;">Request Origin IP:</td>
                                        <td style="padding:6px 0;color:#64748b;font-family:monospace;font-size:12px;">{{ $ipAddress }}</td>
                                    </tr>
                                    @endif
                                </table>
                            </div>

                            <!-- Message Box -->
                            <div style="margin-bottom:24px;">
                                <p style="margin:0 0 8px;font-size:13px;font-weight:700;color:#334155;">Request Message / Notes:</p>
                                <div style="background:#ecfdf5;border-left:4px solid #10b981;padding:14px 16px;border-radius:6px;font-size:13px;color:#065f46;line-height:1.6;white-space:pre-wrap;">{{ $messageText }}</div>
                            </div>
                            
                            <p style="margin:0;font-size:12px;color:#64748b;line-height:1.5;">
                                You can reply directly to this email to contact <strong>{{ $name }}</strong>, or provision their employee account directly from your Admin Dashboard.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding:16px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;">
                            <p style="margin:0;font-size:11px;color:#94a3b8;">
                                &copy; {{ date('Y') }} <a href="https://pinweb.in" target="_blank" rel="noopener noreferrer" style="color:#059669;text-decoration:none;font-weight:600;">Pinweb Private Limited</a>. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
