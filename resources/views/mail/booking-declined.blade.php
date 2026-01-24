<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>Booking Update - InkedIn</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #f5f5f5;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f5f5f5;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                    <!-- Header with Logo -->
                    <tr>
                        <td align="center" style="padding: 40px 40px 30px 40px; background-color: #1a1a1a;">
                            <img src="{{ config('app.url') }}/assets/images/inkedin-logo.png" alt="InkedIn" width="60" height="60" style="display: block; margin-bottom: 12px;">
                            <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #339989; letter-spacing: 1px;">InkedIn</h1>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            <h2 style="margin: 0 0 20px 0; font-size: 24px; font-weight: 600; color: #1a1a1a;">{{ ucfirst($type) }} Request Update</h2>

                            <p style="margin: 0 0 24px 0; font-size: 16px; line-height: 1.6; color: #555555;">
                                Unfortunately, <strong style="color: #1a1a1a;">{{ $artistName }}</strong> is unable to accommodate your {{ $type }} request at this time.
                            </p>

                            <!-- Booking Details -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 24px; border: 1px solid #e5e5e5; border-radius: 8px;">
                                <tr>
                                    <td style="padding: 16px 20px; background-color: #f5f5f5; border-bottom: 1px solid #e5e5e5;">
                                        <p style="margin: 0; font-size: 14px; font-weight: 600; color: #666666;">
                                            Request Details
                                        </p>
                                    </td>
                                </tr>
                                <tr>
                                    <td style="padding: 0;">
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td style="padding: 12px 20px; font-size: 14px; color: #666666; border-bottom: 1px solid #f0f0f0;">Type</td>
                                                <td style="padding: 12px 20px; font-size: 14px; color: #1a1a1a; text-align: right; font-weight: 500; border-bottom: 1px solid #f0f0f0;">{{ ucfirst($type) }}</td>
                                            </tr>
                                            <tr>
                                                <td style="padding: 12px 20px; font-size: 14px; color: #666666; border-bottom: 1px solid #f0f0f0;">Date</td>
                                                <td style="padding: 12px 20px; font-size: 14px; color: #1a1a1a; text-align: right; font-weight: 500; border-bottom: 1px solid #f0f0f0;">{{ $date }}</td>
                                            </tr>
                                            @if($timeRange)
                                            <tr>
                                                <td style="padding: 12px 20px; font-size: 14px; color: #666666;">Time</td>
                                                <td style="padding: 12px 20px; font-size: 14px; color: #1a1a1a; text-align: right; font-weight: 500;">{{ $timeRange }}</td>
                                            </tr>
                                            @endif
                                        </table>
                                    </td>
                                </tr>
                            </table>

                            @if($reason)
                            <!-- Reason -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin-bottom: 24px;">
                                <tr>
                                    <td style="padding: 16px 20px; background-color: #fef3c7; border-radius: 8px; border-left: 4px solid #f59e0b;">
                                        <p style="margin: 0 0 4px 0; font-size: 12px; font-weight: 600; color: #92400e; text-transform: uppercase;">Message from artist</p>
                                        <p style="margin: 0; font-size: 14px; line-height: 1.5; color: #1a1a1a;">{{ $reason }}</p>
                                    </td>
                                </tr>
                            </table>
                            @endif

                            <!-- Button -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="center" style="padding: 8px 0 24px 0;">
                                        <a href="{{ $inboxUrl }}" style="display: inline-block; padding: 16px 40px; background-color: #339989; color: #ffffff; text-decoration: none; font-size: 16px; font-weight: 600; border-radius: 8px;">
                                            Find Other Artists
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #888888; text-align: center;">
                                Don't worry - there are many talented artists on InkedIn who would love to work with you!
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 24px 40px; background-color: #f9f9f9; border-top: 1px solid #e5e5e5; text-align: center;">
                            <p style="margin: 0; font-size: 12px; color: #888888;">
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
