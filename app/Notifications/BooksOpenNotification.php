<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;
use App\Notifications\Traits\RespectsEmailPreferences;
use App\Notifications\Traits\RespectsPushPreferences;

class BooksOpenNotification extends Notification
{
    use Queueable, RespectsEmailPreferences, RespectsPushPreferences;

    public const EVENT_TYPE = 'books_open';

    public function __construct(
        public User $artist
    ) {}

    public function via(object $notifiable): array
    {
        $channels = $this->filterChannelsForUnsubscribed($notifiable, ['mail', 'fcm']);

        return $this->filterChannelsForPush($notifiable, $channels);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:4000');
        $artistUrl = $frontendUrl . '/artists/' . $this->artist->username;

        $artistName = $this->artist->name ?? $this->artist->username;

        $unsubscribeUrl = URL::signedRoute('unsubscribe', ['user' => $notifiable->id], now()->addDays(30));

        return (new MailMessage)
            ->subject("{$artistName} has opened their books! - InkedIn")
            ->view('mail.books-open', [
                'artistName' => $artistName,
                'artistUsername' => $this->artist->username,
                'artistUrl' => $artistUrl,
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        $artistName = $this->artist->name ?? $this->artist->username;

        return (new FcmMessage(notification: new FcmNotification(
            title: "{$artistName} has opened their books!",
            body: 'Book your appointment now on InkedIn.',
        )))
            ->data([
                'type' => self::EVENT_TYPE,
                'artist_id' => (string) $this->artist->id,
            ])
            ->custom([
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                        ],
                    ],
                ],
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'artist_id' => $this->artist->id,
            'artist_username' => $this->artist->username,
        ];
    }

    /**
     * Extra data to log with this notification (for spatie/laravel-notification-log).
     */
    public function logExtra(): array
    {
        return [
            'event_type' => self::EVENT_TYPE,
            'sender_id' => $this->artist->id,
            'sender_type' => User::class,
        ];
    }
}
