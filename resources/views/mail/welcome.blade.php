<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>Welcome to InkedIn</title>
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
                                        <h1 style="margin: 0 0 16px 0; font-size: 36px; font-weight: 700; color: #ffffff;">You're in.</h1>
                                        <p style="margin: 0; font-size: 18px; line-height: 1.5; color: #888888;">
                                            @if($isArtist)
                                                Welcome to the new way to showcase your work.
                                            @else
                                                Welcome to the new way to find your next tattoo.
                                            @endif
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
                                        <h2 style="margin: 0 0 20px 0; font-size: 14px; font-weight: 700; color: #D4A853; text-transform: uppercase; letter-spacing: 1px;">Here's the deal</h2>

                                        @if($isArtist)
                                            <p style="margin: 0 0 24px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                                We're building a platform where clients can discover artists based on style, subject matter, and location. The more detail you add to your profile, the better your chances of being found by the right clients.
                                            </p>

                                            <p style="margin: 0 0 32px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                                Upload your best work, tag your styles, and let your portfolio do the talking. We're just getting started — and we're glad you're here.
                                            </p>
                                        @else
                                            <p style="margin: 0 0 24px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                                We're just getting started. New artists are joining every week, so check back often — the lineup keeps getting better.
                                            </p>

                                            <p style="margin: 0 0 32px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                                If you're into what we're building, share it. The more people in, the stronger the network grows — for everyone.
                                            </p>
                                        @endif

                                        <!-- Button -->
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td align="center" style="padding: 8px 0;">
                                                    <a href="{{ $ctaUrl }}" style="display: inline-block; padding: 16px 48px; background-color: #D4A853; color: #1a1a1a; text-decoration: none; font-size: 16px; font-weight: 600; border-radius: 30px;">
                                                        @if($isArtist)
                                                            Complete Your Profile
                                                        @else
                                                            Start Exploring
                                                        @endif
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Footer -->
                    <tr>
                        <td style="padding: 40px 40px 20px 40px; text-align: center;">
                            <p style="margin: 0 0 12px 0; font-size: 14px; color: #666666;">
                                Want to know when we ship new features?
                            </p>
                            <a href="{{ $updatesUrl }}" style="font-size: 14px; color: #D4A853; text-decoration: none;">
                                Get updates &rarr;
                            </a>
                        </td>
                    </tr>

                    <!-- Copyright -->
                    <tr>
                        <td style="padding: 20px 40px; text-align: center;">
                            <p style="margin: 0; font-size: 12px; color: #444444;">
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
