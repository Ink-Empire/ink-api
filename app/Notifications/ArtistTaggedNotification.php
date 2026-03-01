<?php

namespace App\Notifications;

use App\Models\Tattoo;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;
use App\Notifications\Traits\RespectsEmailPreferences;
use App\Notifications\Traits\RespectsPushPreferences;

class ArtistTaggedNotification extends Notification
{
    use RespectsEmailPreferences, RespectsPushPreferences;

    public const EVENT_TYPE = 'artist_tagged';

    public function __construct(
        public Tattoo $tattoo,
        public User $uploader
    ) {}

    public function via(object $notifiable): array
    {
        $channels = $this->filterChannelsForUnsubscribed($notifiable, ['mail', FcmChannel::class]);
        $channels = $this->filterChannelsForPush($notifiable, $channels);
        $channels[] = 'database';

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => self::EVENT_TYPE,
            'message' => "{$this->uploader->name} tagged you as artist",
            'actor_name' => $this->uploader->name,
            'actor_image' => $this->uploader->image?->uri ?? null,
            'entity_type' => 'tattoo',
            'entity_id' => $this->tattoo->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $reviewUrl = $frontendUrl . '/dashboard';
        $unsubscribeUrl = URL::signedRoute('unsubscribe', ['user' => $notifiable->id], now()->addDays(30));

        return (new MailMessage)
            ->subject("{$this->uploader->name} tagged you as the artist on their tattoo - InkedIn")
            ->view('mail.artist-tagged', [
                'uploaderName' => $this->uploader->name,
                'reviewUrl' => $reviewUrl,
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage(notification: new FcmNotification(
            title: 'New tattoo tag',
            body: "{$this->uploader->name} tagged you as the artist on their tattoo. Tap to review.",
        )))
            ->data([
                'type' => self::EVENT_TYPE,
                'tattoo_id' => (string) $this->tattoo->id,
            ])
            ->custom([
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                            'badge' => 1,
                        ],
                    ],
                ],
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'tattoo_id' => $this->tattoo->id,
            'uploader_id' => $this->uploader->id,
        ];
    }

    public function logExtra(): array
    {
        return [
            'event_type' => self::EVENT_TYPE,
            'sender_id' => $this->uploader->id,
            'sender_type' => User::class,
            'reference_id' => $this->tattoo->id,
            'reference_type' => Tattoo::class,
        ];
    }
}
