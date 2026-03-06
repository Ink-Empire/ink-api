<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>New Tattoo Lead - InkedIn</title>
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
                                        <h1 style="margin: 0 0 16px 0; font-size: 36px; font-weight: 700; color: #ffffff;">New Lead in Your Area!</h1>
                                        <p style="margin: 0; font-size: 18px; line-height: 1.5; color: #888888;">
                                            <strong style="color: #ffffff;">{{ $clientName }}</strong> in <strong style="color: #ffffff;">{{ $location }}</strong> is looking for a tattoo {{ $timing }}.
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
                                        @if($description)
                                        <div style="margin: 0 0 24px 0; padding: 16px; background-color: #252525; border-radius: 12px;">
                                            <p style="margin: 0; font-size: 14px; color: #aaaaaa; font-style: italic;">
                                                "{{ $description }}"
                                            </p>
                                        </div>
                                        @endif

                                        <p style="margin: 0 0 24px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                            They've allowed artists to contact them. Reach out to discuss their tattoo ideas!
                                        </p>

                                        <!-- Button -->
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td align="center" style="padding: 8px 0 32px 0;">
                                                    <a href="{{ $leadsUrl }}" style="display: inline-block; padding: 16px 48px; background-color: #D4A853; color: #1a1a1a; text-decoration: none; font-size: 16px; font-weight: 600; border-radius: 30px;">
                                                        View Lead Details
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>

                                        <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #666666; text-align: center;">
                                            Be one of the first to respond - clients often book with artists who reach out quickly!
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 32px 40px; text-align: center;">
                            <p style="margin: 0 0 8px 0; font-size: 12px; color: #444444;">
                                You're receiving this because you're an artist in the {{ $location }} area on InkedIn.
                            </p>
                            <p style="margin: 0; font-size: 12px; color: #444444;">
                                &copy; {{ date('Y') }} InkedIn. All rights reserved.
                            </p>
                            <p style="margin: 8px 0 0 0; font-size: 12px; color: #444444;">
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
