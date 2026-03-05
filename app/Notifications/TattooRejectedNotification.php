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

class TattooRejectedNotification extends Notification
{
    use RespectsEmailPreferences, RespectsPushPreferences;

    public const EVENT_TYPE = 'tattoo_rejected';

    public function __construct(
        public Tattoo $tattoo,
        public User $artist
    ) {}

    public function via(object $notifiable): array
    {
        // Always include mail — tag responses are transactional, not marketing
        $channels = ['mail'];
        $pushChannels = $this->filterChannelsForPush($notifiable, [FcmChannel::class]);
        $channels = array_merge($channels, $pushChannels);
        $channels[] = 'database';

        return $channels;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => self::EVENT_TYPE,
            'message' => "{$this->artist->name} declined your tag",
            'actor_name' => $this->artist->name,
            'actor_image' => $this->artist->image?->uri ?? null,
            'entity_type' => 'tattoo',
            'entity_id' => $this->tattoo->id,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $unsubscribeUrl = URL::signedRoute('unsubscribe', ['user' => $notifiable->id], now()->addDays(30));

        return (new MailMessage)
            ->subject("{$this->artist->name} didn't approve the tag - InkedIn")
            ->view('mail.tattoo-rejected', [
                'artistName' => $this->artist->name,
                'frontendUrl' => $frontendUrl,
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage(notification: new FcmNotification(
            title: 'Tag not approved',
            body: "{$this->artist->name} didn't approve the tag. Your tattoo is still visible on your profile.",
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
            'artist_id' => $this->artist->id,
        ];
    }

    public function logExtra(): array
    {
        return [
            'event_type' => self::EVENT_TYPE,
            'sender_id' => $this->artist->id,
            'sender_type' => User::class,
            'reference_id' => $this->tattoo->id,
            'reference_type' => Tattoo::class,
        ];
    }
}
