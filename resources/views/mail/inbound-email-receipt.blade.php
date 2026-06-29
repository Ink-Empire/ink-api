<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your Photos Arrived - InkedIn</title>
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
                                {{ $photoCount }} {{ $photoCount === 1 ? 'photo' : 'photos' }} received
                            </h2>

                            @if($isNewAccount)
                            <p style="margin: 0 0 20px 0; font-size: 16px; line-height: 1.6; color: #B0A0A5;">
                                Hey {{ $userName }}, we got your images and created your InkedIn artist account. Use the credentials below to log in, review your photos, and finish setting up your profile.
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

                            <p style="margin: 0 0 24px 0; font-size: 13px; line-height: 1.6; color: #B0A0A5;">
                                You'll be asked to accept our Terms &amp; Conditions on first login — takes about 10 seconds. Change your password in account settings whenever you're ready.
                            </p>
                            @else
                            <p style="margin: 0 0 24px 0; font-size: 16px; line-height: 1.6; color: #B0A0A5;">
                                Hey {{ $userName }}, your {{ $photoCount === 1 ? 'photo is' : 'photos are' }} ready to review. Add placement and style info, then publish.
                            </p>
                            @endif

                            <!-- CTA Button -->
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                <tr>
                                    <td align="center" style="padding: 0 0 32px 0;">
                                        <a href="{{ $reviewUrl }}" style="display: inline-block; padding: 16px 40px; background-color: #D4A853; color: #1A0E11; text-decoration: none; font-size: 16px; font-weight: 600; border-radius: 8px;">
                                            Review your photos
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin: 0; font-size: 14px; line-height: 1.6; color: #B0A0A5;">
                                You can email more photos anytime to the same address to add them to your portfolio.
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
