<?php

namespace App\Notifications;

use App\Models\Studio;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use App\Notifications\Traits\RespectsEmailPreferences;

class ArtistJoinRequestNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsEmailPreferences;

    public const EVENT_TYPE = 'artist_join_request';

    public function __construct(
        public User $artist,
        public Studio $studio
    ) {
        $this->onQueue('mail');
    }

    public function via(object $notifiable): array
    {
        return $this->filterChannelsForUnsubscribed($notifiable, ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        // /dashboard is a flat page, so /dashboard/studio/artists 404s. The
        // studio tab is component state rather than a route, so there is
        // nothing more specific to link to than the dashboard itself.
        $dashboardUrl = $frontendUrl . '/dashboard';

        $artistName = $this->artist->name ?? 'An artist';
        $studioName = $this->studio->name ?? 'your studio';

        $unsubscribeUrl = URL::signedRoute('unsubscribe', ['user' => $notifiable->id], now()->addDays(30));

        return (new MailMessage)
            ->subject("{$artistName} wants to join {$studioName} - InkedIn")
            ->view('mail.artist-join-request', [
                'artistName' => $artistName,
                'artistUsername' => $this->artist->username,
                'studioName' => $studioName,
                'dashboardUrl' => $dashboardUrl,
                'unsubscribeUrl' => $unsubscribeUrl,
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
