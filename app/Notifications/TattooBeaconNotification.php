<?php

namespace App\Notifications;

use App\Models\TattooLead;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;
use App\Notifications\Traits\RespectsEmailPreferences;
use App\Notifications\Traits\RespectsPushPreferences;

class TattooBeaconNotification extends Notification
{
    use Queueable, RespectsEmailPreferences, RespectsPushPreferences;

    public const EVENT_TYPE = 'beacon_request';

    public function __construct(
        public TattooLead $lead,
        public User $client
    ) {}

    public function via(object $notifiable): array
    {
        $channels = $this->filterChannelsForUnsubscribed($notifiable, ['mail', FcmChannel::class]);

        return $this->filterChannelsForPush($notifiable, $channels);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:4000');
        $leadsUrl = $frontendUrl . '/dashboard/leads';

        $clientName = $this->client->name ?? $this->client->username;
        $location = $this->client->location ?? 'your area';
        $timing = $this->getTimingLabel($this->lead->timing);

        $unsubscribeUrl = URL::signedRoute('unsubscribe', ['user' => $notifiable->id], now()->addDays(30));

        return (new MailMessage)
            ->subject("Someone near you is looking for a tattoo! - InkedIn")
            ->view('mail.tattoo-beacon', [
                'clientName' => $clientName,
                'location' => $location,
                'timing' => $timing,
                'description' => $this->lead->description,
                'leadsUrl' => $leadsUrl,
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        return (new FcmMessage(notification: new FcmNotification(
            title: 'New tattoo request nearby!',
            body: 'Someone in your area is looking for a tattoo artist.',
        )))
            ->data([
                'type' => self::EVENT_TYPE,
                'lead_id' => (string) $this->lead->id,
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
            'lead_id' => $this->lead->id,
            'client_id' => $this->client->id,
        ];
    }

    public function logExtra(): array
    {
        return [
            'event_type' => self::EVENT_TYPE,
            'sender_id' => $this->client->id,
            'sender_type' => User::class,
            'reference_id' => $this->lead->id,
            'reference_type' => TattooLead::class,
        ];
    }

    private function getTimingLabel(?string $timing): string
    {
        return match ($timing) {
            'week' => 'this week',
            'month' => 'this month',
            'year' => 'this year',
            default => 'soon',
        };
    }
}
