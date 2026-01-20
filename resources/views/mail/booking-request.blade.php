<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Booking Request - InkedIn</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #1A0E11;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #1A0E11; min-height: 100vh;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 600px; background-color: #2D1F23; border-radius: 12px; overflow: hidden;">
                    <!-- Header with Logo -->
                    <tr>
                        <td align="center" style="padding: 40px 40px 30px 40px; border-bottom: 1px solid rgba(255, 255, 255, 0.1);">
                            <img src="{{ config('app.url') }}/assets/img/logo.png" alt="InkedIn" width="80" height="80" style="display: block; margin-bottom: 16px;">
                            <h1 style="margin: 0; font-size: 32px; font-weight: 700; color: #D4A853; letter-spacing: 1px;">InkedIn</h1>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            <h2 style="margin: 0 0 20px 0; font-size: 24px; font-weight: 600; color: #FFFFFF;">New {{ ucfirst($type) }} Request</h2>

                            <p style="margin: 0 0 24px 0; font-size: 16px; line-height: 1.6; color: #B0A0A5;">
                                <strong style="color: #FFFFFF;">{{ $clientName }}</strong> has requested a {{ $type }} with you.
                            </p>

                            <!-- Booking Details -->
                            <div style="padding: 20px; background-color: rgba(0, 0, 0, 0.2); border-radius: 8px; margin-bottom: 24px;">
                                <p style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600; color: #FFFFFF;">
                                    Request Details:
                                </p>
                                <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                    <tr>
                                        <td style="padding: 8px 0; font-size: 14px; color: #B0A0A5;">Type:</td>
                                        <td style="padding: 8px 0; font-size: 14px; color: #FFFFFF; text-align: right;">{{ ucfirst($type) }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding: 8px 0; font-size: 14px; color: #B0A0A5;">Requested Date:</td>
                                        <td style="padding: 8px 0; font-size: 14px; color: #FFFFFF; text-align: right;">{{ $date }}</td>
                                    </tr>
                                    @if($timeRange)
                                    <tr>
                                        <td style="padding: 8px 0; font-size: 14px; color: #B0A0A5;">Requested Time:</td>
                                        <td style="padding: 8px 0; font-size: 14px; color: #FFFFFF; text-align: right;">{{ $timeRange }}</td>
                                    </tr>
                                    @endif
                                </table>
                            </div>

                            @if($description)
                            <!-- Client Notes -->
                            <div style="padding: 20px; background-color: rgba(0, 0, 0, 0.2); border-radius: 8px; margin-bottom: 24px;">
                                <p style="margin: 0 0 12px 0; font-size: 14px; font-weight: 600; color: #FFFFFF;">
                                    Client's Notes:
                                </p>
                                <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #B0A0A5;">
                                    {{ $description }}
                                </p>
                            </div>
                            @endif

                            <!-- Button -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="center" style="padding: 16px 0 32px 0;">
                                        <a href="{{ $inboxUrl }}" style="display: inline-block; padding: 16px 40px; background-color: #D4A853; color: #1A0E11; text-decoration: none; font-size: 16px; font-weight: 600; border-radius: 8px;">
                                            View in Inbox
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #B0A0A5;">
                                Log in to your InkedIn dashboard to respond to this request.
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
