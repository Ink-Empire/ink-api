<?php

namespace App\Notifications;

use App\Models\Studio;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ArtistJoinRequestNotification extends Notification
{
    public const EVENT_TYPE = 'artist_join_request';

    public function __construct(
        public User $artist,
        public Studio $studio
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $dashboardUrl = $frontendUrl . '/dashboard/studio/artists';

        $artistName = $this->artist->name ?? 'An artist';
        $studioName = $this->studio->name ?? 'your studio';

        return (new MailMessage)
            ->subject("{$artistName} wants to join {$studioName} - InkedIn")
            ->view('mail.artist-join-request', [
                'artistName' => $artistName,
                'artistUsername' => $this->artist->username,
                'studioName' => $studioName,
                'dashboardUrl' => $dashboardUrl,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'artist_id' => $this->artist->id,
            'artist_name' => $this->artist->name,
            'studio_id' => $this->studio->id,
        ];
    }

    public function logExtra(): array
    {
        return [
            'event_type' => self::EVENT_TYPE,
            'sender_id' => $this->artist->id,
            'sender_type' => User::class,
            'reference_id' => $this->studio->id,
            'reference_type' => Studio::class,
        ];
    }
}
