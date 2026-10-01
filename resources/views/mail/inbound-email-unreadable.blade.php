<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>We couldn't open your photos - InkedIn</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #1A0E11;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #1A0E11; min-height: 100vh;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 600px; background-color: #2D1F23; border-radius: 12px; overflow: hidden;">
                    <!-- Header -->
                    <tr>
                        <td align="center" style="padding: 40px 40px 30px 40px; border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
                            <img src="{{ config('app.frontend_url') }}/assets/images/inkedin-logo.png" alt="InkedIn" width="200" style="display: block; max-width: 100%;">
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            <h2 style="margin: 0 0 16px 0; font-size: 24px; font-weight: 600; color: #FFFFFF;">
                                We couldn't open your {{ $attemptedCount === 1 ? 'photo' : 'photos' }}
                            </h2>

                            <p style="margin: 0 0 20px 0; font-size: 16px; line-height: 1.6; color: #B0A0A5;">
                                Hey {{ $userName }}, your email reached us but we couldn't read {{ $attemptedCount === 1 ? 'the attachment' : 'any of the attachments' }}, so nothing was added to your portfolio.
                            </p>

                            <p style="margin: 0 0 24px 0; font-size: 16px; line-height: 1.6; color: #B0A0A5;">
                                This is usually an iPhone sending HEIC instead of JPEG. On iOS you can fix it in Settings, Camera, Formats, by choosing Most Compatible. Or send the photos again from your photo library, which converts them on the way out.
                            </p>

                            @if($isNewAccount)
                            <p style="margin: 0 0 20px 0; font-size: 16px; line-height: 1.6; color: #B0A0A5;">
                                We created your InkedIn artist account anyway, so it's waiting for you. Here is how to get into it.
                            </p>

                            <!-- Credentials box -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 24px;">
                                <tr>
                                    <td style="background-color: #1A0E11; border: 1px solid rgba(212, 168, 83, 0.3); border-radius: 8px; padding: 20px 24px;">
                                        <p style="margin: 0 0 8px 0; font-size: 12px; font-weight: 600; color: #D4A853; text-transform: uppercase; letter-spacing: 1px;">Your login credentials</p>
                                        <p style="margin: 0 0 6px 0; font-size: 14px; color: #B0A0A5;">Email: <span style="color: #FFFFFF;">{{ $userEmail }}</span></p>
                                        <p style="margin: 0 0 16px 0; font-size: 14px; color: #B0A0A5;">Temp password: <span style="color: #FFFFFF; font-family: monospace; letter-spacing: 1px;">{{ $tempPassword }}</span></p>
                                        <a href="{{ $loginUrl }}" style="display: inline-block; padding: 10px 24px; background-color: #D4A853; color: #1A0E11; text-decoration: none; font-size: 14px; font-weight: 600; border-radius: 6px;">Log in to InkedIn</a>
                                    </td>
                                </tr>
                            </table>
                            @endif

                            <!-- CTA Button -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="center" style="padding: 0 0 32px 0;">
                                        <a href="{{ $uploadUrl }}" style="display: inline-block; padding: 16px 40px; background-color: #D4A853; color: #1A0E11; text-decoration: none; font-size: 16px; font-weight: 600; border-radius: 8px;">
                                            Upload from your dashboard
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #B0A0A5;">
                                You can also just reply to this email. A real person reads these, and we would rather sort it out than have you give up on it.
                            </p>
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
