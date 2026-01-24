<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const EVENT_TYPE = 'welcome';

    public function __construct() {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:4000');
        $exploreUrl = $frontendUrl . '/tattoos';
        $updatesUrl = $frontendUrl . '/updates';

        return (new MailMessage)
            ->subject("You're in! Welcome to InkedIn")
            ->view('mail.welcome', [
                'exploreUrl' => $exploreUrl,
                'updatesUrl' => $updatesUrl,
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
