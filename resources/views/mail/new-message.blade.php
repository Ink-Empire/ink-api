<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>New Message - InkedIn</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #f5f5f5;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #f5f5f5;">
        <tr>
            <td align="center" style="padding: 40px 20px;">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width: 600px; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">
                    <!-- Header with Logo -->
                    <tr>
                        <td align="center" style="padding: 40px 40px 30px 40px; background-color: #1a1a1a;">
                            <img src="{{ config('app.url') }}/assets/images/inkedin-logo.png" alt="InkedIn" width="200" style="display: block; height: auto; margin-bottom: 12px;">
                            <h1 style="margin: 0; font-size: 28px; font-weight: 700; color: #339989; letter-spacing: 1px;">InkedIn</h1>
                        </td>
                    </tr>

                    <!-- Content -->
                    <tr>
                        <td style="padding: 40px;">
                            <h2 style="margin: 0 0 20px 0; font-size: 24px; font-weight: 600; color: #1a1a1a;">New Message</h2>

                            <p style="margin: 0 0 24px 0; font-size: 16px; line-height: 1.6; color: #555555;">
                                Hey {{ $recipientName }}, you have a new message from <strong style="color: #1a1a1a;">{{ $senderName }}</strong>.
                            </p>

                            <!-- Button -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="center" style="padding: 8px 0 24px 0;">
                                        <a href="{{ $inboxUrl }}" style="display: inline-block; padding: 16px 40px; background-color: #339989; color: #ffffff; text-decoration: none; font-size: 16px; font-weight: 600; border-radius: 8px;">
                                            View Message
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #888888; text-align: center;">
                                Log in to your InkedIn inbox to reply.
                            </p>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 24px 40px; background-color: #f9f9f9; border-top: 1px solid #e5e5e5; text-align: center;">
                            <p style="margin: 0; font-size: 12px; color: #888888;">
                                &copy; {{ date('Y') }} InkedIn. All rights reserved.
                            </p>
                            <p style="margin: 8px 0 0 0; font-size: 11px; color: #aaaaaa;">
                                <a href="{{ $unsubscribeUrl }}" style="color: #888888; text-decoration: underline;">Unsubscribe</a> from InkedIn emails
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
