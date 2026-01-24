<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Your Email - InkedIn</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #1A0E11;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #1A0E11; min-height: 100vh;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 600px; background-color: #2D1F23; border-radius: 12px; overflow: hidden;">
                    <!-- Header with Logo -->
                    <tr>
                        <td align="center" style="padding: 40px 40px 30px 40px; border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
                            <img src="{{ config('app.url') }}/assets/images/inkedin-logo.png" alt="InkedIn" width="200" style="display: block; height: auto; margin-bottom: 16px;">
                            <h1 style="margin: 0; font-size: 32px; font-weight: 700; color: #D4A853; letter-spacing: 1px;">InkedIn</h1>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            <h2 style="margin: 0 0 20px 0; font-size: 24px; font-weight: 600; color: #FFFFFF;">Verify Your Email Address</h2>

                            <p style="margin: 0 0 24px 0; font-size: 16px; line-height: 1.6; color: #B0A0A5;">
                                Welcome to InkedIn! Please click the button below to verify your email address and start discovering amazing tattoo artists.
                            </p>

                            <!-- Spam Warning -->
                            <div style="padding: 16px; background-color: rgba(212, 168, 83, 0.1); border: 1px solid rgba(212, 168, 83, 0.3); border-radius: 8px; margin-bottom: 24px;">
                                <p style="margin: 0; font-size: 14px; line-height: 1.5; color: #D4A853;">
                                    <strong>Can't find this email?</strong> Check your spam or junk folder. To ensure you receive our emails, add <strong>noreply@getinked.in</strong> to your contacts.
                                </p>
                            </div>

                            <!-- Button -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="center" style="padding: 16px 0 32px 0;">
                                        <a href="{{ $url }}" style="display: inline-block; padding: 16px 40px; background-color: #D4A853; color: #1A0E11; text-decoration: none; font-size: 16px; font-weight: 600; border-radius: 8px;">
                                            Verify Email Address
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0 0 16px 0; font-size: 14px; line-height: 1.6; color: #B0A0A5;">
                                This verification link will expire in 60 minutes.
                            </p>

                            <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6; color: #B0A0A5;">
                                If you did not create an account, no further action is required.
                            </p>

                            <!-- Fallback Link -->
                            <div style="padding: 20px; background-color: rgba(0, 0, 0, 0.2); border-radius: 8px;">
                                <p style="margin: 0 0 8px 0; font-size: 12px; color: #B0A0A5;">
                                    If you're having trouble clicking the button, copy and paste the URL below into your browser:
                                </p>
                                <p style="margin: 0; font-size: 12px; word-break: break-all; color: #D4A853;">
                                    {{ $url }}
                                </p>
                            </div>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 30px 40px; border-top: 1px solid rgba(255, 255, 255, 0.1); text-align: center;">
                            <p style="margin: 0; font-size: 12px; color: #B0A0A5;">
                                &copy; {{ date('Y') }} InkedIn. All rights reserved.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
