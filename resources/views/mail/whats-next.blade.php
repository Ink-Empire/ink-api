@php use App\Enums\UserTypes; @endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark">
    <meta name="supported-color-schemes" content="dark">
    <title>What's next on InkedIn</title>
</head>
<body style="margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; background-color: #0a0a0a;">
    <div style="display: none; font-size: 1px; line-height: 1px; max-height: 0; max-width: 0; opacity: 0; overflow: hidden; mso-hide: all;">
        {{ $preheader }}
    </div>

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
                                        <h1 style="margin: 0 0 16px 0; font-size: 36px; font-weight: 700; color: #D4A853;">Here's what's next.</h1>
                                        <p style="margin: 0; font-size: 18px; line-height: 1.5; color: #888888;">
                                            @if($audience === UserTypes::ARTIST)
                                                You've had a look around. Here's what actually gets your work in front of people.
                                            @elseif($audience === UserTypes::CLIENT)
                                                You've had a look around. Here's how to turn browsing into a booking.
                                            @elseif($audience === UserTypes::STUDIO)
                                                You've had a look around. Here's what keeps a shop page working after setup.
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
                                        @if($audience === UserTypes::ARTIST)
                                            <p style="margin: 0 0 16px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                                <strong style="color: #ffffff;">Tag your styles and subjects.</strong> Search runs on them. An untagged portfolio is close to invisible to someone looking for your kind of work.
                                            </p>

                                            <p style="margin: 0 0 16px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                                <strong style="color: #ffffff;">Say whether your books are open.</strong> It is the first thing people check, and it decides whether they bother asking.
                                            </p>

                                            <p style="margin: 0 0 32px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                                <strong style="color: #ffffff;">Answer the people who reach out.</strong> Booking requests and messages come through the dashboard. A quick reply is most of what turns an enquiry into a chair.
                                            </p>
                                        @elseif($audience === UserTypes::CLIENT)
                                            <p style="margin: 0 0 16px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                                <strong style="color: #ffffff;">Save the work you keep coming back to.</strong> Saving artists and tattoos gives you somewhere to compare them side by side instead of losing them in a feed.
                                            </p>

                                            <p style="margin: 0 0 16px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                                <strong style="color: #ffffff;">Search by style, subject and city.</strong> Narrowing by what you actually want beats scrolling, especially if you are flexible on who does it.
                                            </p>

                                            <p style="margin: 0 0 32px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                                <strong style="color: #ffffff;">Tell artists what you are after.</strong> Describe the piece and your timing, and artists who want that work can come to you.
                                            </p>
                                        @elseif($audience === UserTypes::STUDIO)
                                            <p style="margin: 0 0 16px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                                <strong style="color: #ffffff;">Answer the artists asking to join.</strong> Join requests land with you, and an unanswered one is a name missing from your page.
                                            </p>

                                            <p style="margin: 0 0 16px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                                <strong style="color: #ffffff;">Put your best work up front.</strong> You can pin particular artists and tattoos to the top of your page, so the first thing people see is the thing you want them to see.
                                            </p>

                                            <p style="margin: 0 0 32px 0; font-size: 16px; line-height: 1.7; color: #aaaaaa;">
                                                <strong style="color: #ffffff;">Post what is going on.</strong> Guest spots, openings, a new artist starting. A page that changes is worth coming back to.
                                            </p>
                                        @endif

                                        <!-- Button -->
                                        <table role="presentation" width="100%" cellspacing="0" cellpadding="0">
                                            <tr>
                                                <td align="center" style="padding: 8px 0;">
                                                    <a href="{{ $ctaUrl }}" style="display: inline-block; padding: 16px 48px; background-color: #D4A853; color: #1a1a1a; text-decoration: none; font-size: 16px; font-weight: 600; border-radius: 30px;">
                                                        @if($audience === UserTypes::ARTIST)
                                                            Go to your dashboard
                                                        @elseif($audience === UserTypes::CLIENT)
                                                            Start exploring
                                                        @elseif($audience === UserTypes::STUDIO)
                                                            Go to your dashboard
                                                        @endif
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>

                                        <p style="margin: 32px 0 0 0; font-size: 16px; line-height: 1.7; color: #aaaaaa; font-style: italic;">
                                            We're early, and I read everything that comes back to this address. If something's broken or annoying, don't hesitate to reach out.
                                        </p>

                                        <p style="margin: 16px 0 0 0; font-size: 16px; line-height: 1.7; color: #aaaaaa; font-style: italic;">
                                            -Caroline, founder of InkedIn
                                        </p>
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
