<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>Studio Invitation - InkedIn</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #0a0a0a;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #0a0a0a;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 600px;">
                    <!-- Logo -->
                    <tr>
                        <td align="center" style="padding: 0 0 32px 0;">
                            <img src="{{ config('app.url') }}/assets/images/inkedin-logo.png" alt="InkedIn" width="200" style="display: block; height: auto;">
                        </td>
                    </tr>

                    <!-- Main Card -->
                    <tr>
                        <td>
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #1a1a1a; border-radius: 16px; overflow: hidden;">
                                <!-- Header Section -->
                                <tr>
                                    <td style="padding: 48px 40px 32px 40px; text-align: center;">
                                        <h1 style="margin: 0 0 16px 0; font-size: 36px; font-weight: 700; color: #ffffff;">You've Been Invited!</h1>
                                        <p style="margin: 0; font-size: 18px; line-height: 1.5; color: #888888;">
                                            <strong style="color: #ffffff;">{{ $inviterName }}</strong> has invited you to join <strong style="color: #ffffff;">{{ $studioName }}</strong> on InkedIn.
                                        </p>
                                    </td>
                                </tr>

                                <!-- Divider -->
                                <tr>
                                    <td style="padding: 0 40px;">
                                        <div style="height: 1px; background-color: #333333;"></div>
                                    </td>
                                </tr>

                                <!-- Content Section -->
                                <tr>
                                    <td style="padding: 32px 40px 40px 40px;">
                                        <!-- Studio Details -->
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 24px; background-color: #252525; border-radius: 12px;">
                                            <tr>
                                                <td style="padding: 16px 20px; border-bottom: 1px solid #333333;">
                                                    <p style="margin: 0; font-size: 14px; font-weight: 600; color: #ffffff;">
                                                        Studio Details
                                                    </p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 0;">
                                                    <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                                        <tr>
                                                            <td style="padding: 12px 20px; font-size: 14px; color: #888888; border-bottom: 1px solid #333333;">Studio Name</td>
                                                            <td style="padding: 12px 20px; font-size: 14px; color: #ffffff; text-align: right; font-weight: 500; border-bottom: 1px solid #333333;">{{ $studioName }}</td>
                                                        </tr>
                                                        @if($studioLocation)
                                                        <tr>
                                                            <td style="padding: 12px 20px; font-size: 14px; color: #888888;">Location</td>
                                                            <td style="padding: 12px 20px; font-size: 14px; color: #ffffff; text-align: right; font-weight: 500;">{{ $studioLocation }}</td>
                                                        </tr>
                                                        @endif
                                                    </table>
                                                </td>
                                            </tr>
                                        </table>

                                        <p style="margin: 0 0 24px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                            Joining a studio on InkedIn allows you to be featured on their profile and increases your visibility to potential clients.
                                        </p>

                                        <!-- Button -->
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td align="center" style="padding: 8px 0 32px 0;">
                                                    <a href="{{ $dashboardUrl }}" style="display: inline-block; padding: 16px 48px; background-color: #D4A853; color: #1a1a1a; text-decoration: none; font-size: 16px; font-weight: 600; border-radius: 30px;">
                                                        View Invitation
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>

                                        <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #666666; text-align: center;">
                                            Log in to your InkedIn dashboard to accept or decline this invitation.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Copyright -->
                    <tr>
                        <td style="padding: 20px 40px; text-align: center;">
                            <p style="margin: 0; font-size: 12px; color: #444444;">
                                &copy; {{ date('Y') }} InkedIn. All rights reserved.
                            </p>
                            <p style="margin: 8px 0 0 0; font-size: 11px; color: #444444;">
                                <a href="{{ $unsubscribeUrl }}" style="color: #444444; text-decoration: underline;">Unsubscribe</a> from InkedIn emails
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
