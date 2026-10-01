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

    /**
     * Forces a version of the email regardless of the notifiable.
     *
     * Only the admin preview sets this. It sends through
     * Notification::route(), which has no account behind it and therefore no
     * type_id, and it needs to be able to show any of the three on demand. A
     * real send leaves this null and resolves from the account.
     */
    public function __construct(private ?string $audience = null)
    {
        if ($this->audience !== null && ! in_array($this->audience, self::AUDIENCES, true)) {
            throw new InvalidArgumentException("WelcomeNotification has no copy for audience {$this->audience}.");
        }
    }

    /**
     * @var list<string>
     */
    private const AUDIENCES = [
        UserTypes::CLIENT,
        UserTypes::ARTIST,
        UserTypes::STUDIO,
    ];

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

        // The admin preview sends to a bare address, so there is no account to
        // key these to. The links resolve to the invalid-link page rather than
        // emitting an undefined property warning and signing a null id.
        $userId = $notifiable->id ?? null;

        // Generate a signed URL for subscribing to updates (valid for 30 days)
        $updatesUrl = URL::signedRoute('subscribe', ['user' => $userId], now()->addDays(30));

        $unsubscribeUrl = URL::signedRoute('unsubscribe', ['user' => $userId], now()->addDays(30));

        return (new MailMessage)
            ->subject($subject)
            ->view('mail.welcome', [
                'ctaUrl' => $ctaUrl,
                'updatesUrl' => $updatesUrl,
                'audience' => $audience,
                'preheader' => $preheader,
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
        if ($this->audience !== null) {
            return $this->audience;
        }

        return match ($notifiable->type_id ?? null) {
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
