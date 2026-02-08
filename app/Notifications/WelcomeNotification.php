<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use App\Notifications\Traits\RespectsEmailPreferences;

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
        $isArtist = $notifiable->type_id === 2;

        // Different CTAs for artists vs clients
        $ctaUrl = $isArtist
            ? $frontendUrl . '/dashboard'
            : $frontendUrl . '/tattoos';

        // Generate a signed URL for subscribing to updates (valid for 30 days)
        $updatesUrl = URL::signedRoute('subscribe', ['user' => $notifiable->id], now()->addDays(30));

        $unsubscribeUrl = URL::signedRoute('unsubscribe', ['user' => $notifiable->id], now()->addDays(30));

        return (new MailMessage)
            ->subject("You're in! Welcome to InkedIn")
            ->view('mail.welcome', [
                'ctaUrl' => $ctaUrl,
                'updatesUrl' => $updatesUrl,
                'isArtist' => $isArtist,
                'userName' => $notifiable->name,
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
