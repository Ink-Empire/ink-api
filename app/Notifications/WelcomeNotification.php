<?php

namespace App\Notifications;

use App\Enums\UserTypes;
use App\Notifications\Traits\RespectsEmailPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

class WelcomeNotification extends Notification
{
    use Queueable, RespectsEmailPreferences;

    public const EVENT_TYPE = 'welcome';

    public function via(object $notifiable): array
    {
        return $this->filterChannelsForUnsubscribed($notifiable, ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:4000');
        $audience = $this->audienceFor($notifiable);

        $ctaUrl = $frontendUrl . match ($audience) {
            UserTypes::ARTIST => '/dashboard',
            UserTypes::STUDIO => '/dashboard',
            UserTypes::CLIENT => '/tattoos',
        };

        // Deliberately does not restate the headline, which already says
        // "You're signed up". The subject and the preheader are the only two
        // things a recipient reads before deciding to open.
        $subject = match ($audience) {
            UserTypes::CLIENT => "What's next: finding your tattoo",
            UserTypes::ARTIST => "What's next: getting your work seen",
            UserTypes::STUDIO => "What's next: getting your shop on the map",
        };

        $preheader = match ($audience) {
            UserTypes::CLIENT => 'Browse by style, subject and city to find the artist you want.',
            UserTypes::ARTIST => 'Add your work and tag your styles so the right clients can find you.',
            UserTypes::STUDIO => 'Add your address, pick a layout and bring your artists in.',
        };

        // Generate a signed URL for subscribing to updates (valid for 30 days)
        $updatesUrl = URL::signedRoute('subscribe', ['user' => $notifiable->id], now()->addDays(30));

        $unsubscribeUrl = URL::signedRoute('unsubscribe', ['user' => $notifiable->id], now()->addDays(30));

        return (new MailMessage)
            ->subject($subject)
            ->view('mail.welcome', [
                'ctaUrl' => $ctaUrl,
                'updatesUrl' => $updatesUrl,
                'audience' => $audience,
                'preheader' => $preheader,
                'userName' => $notifiable->name,
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
    }

    /**
     * Which of the three versions of this email the account should receive.
     *
     * Deliberately exhaustive. This used to be an is-artist boolean, which
     * sorted every studio owner into the client branch and sent a shop owner
     * off to browse the public tattoo feed. An unrecognised type throws so a
     * fourth account type surfaces as a failed job rather than as somebody
     * quietly receiving copy written for a different audience.
     */
    private function audienceFor(object $notifiable): string
    {
        return match ($notifiable->type_id) {
            UserTypes::CLIENT_TYPE_ID => UserTypes::CLIENT,
            UserTypes::ARTIST_TYPE_ID => UserTypes::ARTIST,
            UserTypes::STUDIO_TYPE_ID => UserTypes::STUDIO,
            default => throw new InvalidArgumentException(
                "WelcomeNotification has no copy for type_id {$notifiable->type_id}."
            ),
        };
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }

    /**
     * Extra data to log with this notification (for spatie/laravel-notification-log).
     */
    public function logExtra(): array
    {
        return [
            'event_type' => self::EVENT_TYPE,
        ];
    }
}
