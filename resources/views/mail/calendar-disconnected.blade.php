<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>Calendar Disconnected - InkedIn</title>
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
                                        <h1 style="margin: 0 0 16px 0; font-size: 36px; font-weight: 700; color: #ffffff;">Calendar Disconnected</h1>
                                        <p style="margin: 0; font-size: 18px; line-height: 1.5; color: #888888;">
                                            Your Google Calendar has stopped syncing with InkedIn.
                                        </p>
                                    </td>
                                </tr>

                                <!-- Divider -->
                                <tr>
                                    <td style="padding: 0 40px;">
                                        <div style="height: 1px; background-color: #333333;"></div>
                                    </td>
                                </tr>

                                <!-- Body Section -->
                                <tr>
                                    <td style="padding: 32px 40px;">
                                        <p style="margin: 0 0 20px 0; font-size: 16px; line-height: 1.6; color: #cccccc;">
                                            Hi {{ $name }},
                                        </p>
                                        <p style="margin: 0 0 20px 0; font-size: 16px; line-height: 1.6; color: #cccccc;">
                                            Google has stopped accepting our connection to <strong style="color: #ffffff;">{{ $providerEmail }}</strong>. This usually happens when access is revoked or the account password changes.
                                        </p>
                                        <p style="margin: 0 0 20px 0; font-size: 16px; line-height: 1.6; color: #cccccc;">
                                            Until you reconnect it, your InkedIn appointments will not appear on your Google Calendar and busy time from that calendar will not block your availability. That means clients could book you when you are not free.
                                        </p>
                                    </td>
                                </tr>

                                <!-- Button -->
                                <tr>
                                    <td align="center" style="padding: 0 40px 48px 40px;">
                                        <a href="{{ $reconnectUrl }}" style="display: inline-block; padding: 16px 40px; background-color: #ffffff; color: #0a0a0a; font-size: 16px; font-weight: 600; text-decoration: none; border-radius: 8px;">Reconnect Calendar</a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td align="center" style="padding: 32px 20px 0 20px;">
                            <p style="margin: 0; font-size: 13px; line-height: 1.5; color: #666666;">
                                You are receiving this because your calendar connection needs attention.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
