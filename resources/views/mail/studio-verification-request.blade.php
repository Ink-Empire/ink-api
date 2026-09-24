<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>Confirming your studio listing - InkedIn</title>
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
                                        <h1 style="margin: 0 0 16px 0; font-size: 32px; font-weight: 700; color: #ffffff;">Confirming your studio listing</h1>
                                        <p style="margin: 0; font-size: 18px; line-height: 1.5; color: #888888;">
                                            We check studio listings from time to time. Here is what we need from you.
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
                                            Hi,
                                        </p>

                                        <p style="margin: 0 0 16px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                            We are confirming the details behind the listing for <strong style="color: #ffffff;">{{ $studioName }}</strong> on InkedIn, and we need to hear from you before the page goes back up.
                                        </p>

                                        <p style="margin: 0 0 24px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                            While we wait, the page is not showing publicly. Nothing has been deleted. Your account, your login and everything you have added to the page are exactly as you left them, and you can still sign in and keep working on it.
                                        </p>

                                        <p style="margin: 0 0 16px 0; font-size: 16px; line-height: 1.7; color: #ffffff; font-weight: 600;">
                                            Either of these works
                                        </p>

                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin: 0 0 24px 0;">
                                            <tr>
                                                <td style="padding: 0 0 16px 0;">
                                                    <p style="margin: 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                                        <strong style="color: #ffffff;">Reply from the studio's own email domain.</strong>
                                                        If the shop has its own domain, a reply from an address at that domain is all we need.
                                                    </p>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td>
                                                    <p style="margin: 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                                        <strong style="color: #ffffff;">Ask us to call the shop.</strong>
                                                        Reply with the address of the studio's own website and a good time to reach you, and we will ring the number published there.
                                                    </p>
                                                </td>
                                            </tr>
                                        </table>

                                        <p style="margin: 0 0 24px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                            @if($replyTo)
                                                Just reply to this email, or write to <strong style="color: #ffffff;">{{ $replyTo }}</strong>. Once we have confirmed it, we will put the page straight back.
                                            @else
                                                Just reply to this email. Once we have confirmed it, we will put the page straight back.
                                            @endif
                                        </p>

                                        <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #666666;">
                                            If anything here is unclear, reply and tell us. A real person reads these.
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
                                You received this email because this address registered a studio on InkedIn.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
