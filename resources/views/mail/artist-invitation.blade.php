<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>Claim Your Tattoo Work - InkedIn</title>
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
                                        <h1 style="margin: 0 0 16px 0; font-size: 36px; font-weight: 700; color: #ffffff;">Someone is showing off your work!</h1>
                                        <p style="margin: 0; font-size: 18px; line-height: 1.5; color: #888888;">
                                            Claim your profile and start growing your client base.
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
                                        <p style="margin: 0 0 16px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                            Hey {{ $artistName }},
                                        </p>

                                        <p style="margin: 0 0 24px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                            <strong style="color: #ffffff;">{{ $clientName }}</strong> just posted your tattoo on InkedIn and tagged you as the artist. Claim your profile to own your portfolio, get discovered, and connect with new clients.
                                        </p>

                                        <!-- Button -->
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td align="center" style="padding: 8px 0 32px 0;">
                                                    <a href="{{ $claimUrl }}" style="display: inline-block; padding: 16px 48px; background-color: #D4A853; color: #1a1a1a; text-decoration: none; font-size: 16px; font-weight: 600; border-radius: 30px;">
                                                        Claim Your Profile
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>

                                        <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #666666; text-align: center;">
                                            Join hundreds of artists already growing their client base on InkedIn.
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 32px 40px; text-align: center;">
                            <p style="margin: 0; font-size: 12px; color: #444444;">
                                &copy; {{ date('Y') }} InkedIn. All rights reserved.
                            </p>
                            <p style="margin: 8px 0 0 0; font-size: 12px; color: #444444;">
                                You received this email because someone attributed tattoo work to you on InkedIn.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
