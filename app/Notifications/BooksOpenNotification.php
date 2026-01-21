<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BooksOpenNotification extends Notification
{
    use Queueable;

    public const EVENT_TYPE = 'books_open';

    public function __construct(
        public User $artist
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:4000');
        $artistUrl = $frontendUrl . '/artists/' . $this->artist->username;

        $artistName = $this->artist->name ?? $this->artist->username;

        return (new MailMessage)
            ->subject("{$artistName} has opened their books! - InkedIn")
            ->view('mail.books-open', [
                'artistName' => $artistName,
                'artistUsername' => $this->artist->username,
                'artistUrl' => $artistUrl,
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
