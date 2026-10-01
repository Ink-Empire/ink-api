<?php

namespace App\Notifications;

use App\Enums\UserTypes;
use App\Notifications\Traits\ResolvesAccountAudience;
use App\Notifications\Traits\RespectsEmailPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * The second touch, about a week after signup.
 *
 * The welcome lands before anyone has had a chance to do anything. This one
 * goes to people who came back and signed in, so it can talk about the things
 * that only matter once an account is real.
 */
class WhatsNextNotification extends Notification
{
    use Queueable, ResolvesAccountAudience, RespectsEmailPreferences;

    public const EVENT_TYPE = 'whats_next';

    /**
     * Only the admin preview sets this. See WelcomeNotification.
     */
    public function __construct(private ?string $audience = null)
    {
        self::assertKnownAudience($this->audience);
    }

    public function via(object $notifiable): array
    {
        return $this->filterChannelsForUnsubscribed($notifiable, ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:4000');
        $audience = $this->audienceFor($notifiable, $this->audience);

        $ctaUrl = $frontendUrl.match ($audience) {
            UserTypes::ARTIST => '/dashboard',
            UserTypes::STUDIO => '/dashboard',
            UserTypes::CLIENT => '/tattoos',
        };

        $subject = match ($audience) {
            UserTypes::CLIENT => "What's next: finding your tattoo",
            UserTypes::ARTIST => "What's next: getting your work seen",
            UserTypes::STUDIO => "What's next: getting your shop on the map",
        };

        $preheader = match ($audience) {
            UserTypes::CLIENT => 'Save the work you like, and tell artists what you are after.',
            UserTypes::ARTIST => 'Tag your styles, open your books and answer the people asking.',
            UserTypes::STUDIO => 'Keep the page current, and answer the artists asking to join.',
        };

        $userId = $notifiable->id ?? null;

        $updatesUrl = URL::signedRoute('subscribe', ['user' => $userId], now()->addDays(30));

        $unsubscribeUrl = URL::signedRoute('unsubscribe', ['user' => $userId], now()->addDays(30));

        return (new MailMessage)
            ->subject($subject)
            ->view('mail.whats-next', [
                'ctaUrl' => $ctaUrl,
                'updatesUrl' => $updatesUrl,
                'audience' => $audience,
                'preheader' => $preheader,
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
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
