<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;
use App\Notifications\Traits\RespectsEmailPreferences;
use App\Notifications\Traits\RespectsPushPreferences;

class BookingAcceptedNotification extends Notification
{
    use RespectsEmailPreferences, RespectsPushPreferences;

    public const EVENT_TYPE = 'booking_accepted';

    public function __construct(
        public Appointment $appointment
    ) {}

    public function via(object $notifiable): array
    {
        $channels = $this->filterChannelsForUnsubscribed($notifiable, ['mail', FcmChannel::class]);

        return $this->filterChannelsForPush($notifiable, $channels);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $inboxUrl = $frontendUrl . '/inbox';

        $artistName = $this->appointment->artist?->name ?? $this->appointment->artist?->username ?? 'The artist';
        $type = $this->appointment->type === 'consultation' ? 'consultation' : 'appointment';
        $date = $this->appointment->date?->format('F j, Y') ?? 'TBD';
        $startTime = $this->appointment->start_time ? date('g:i A', strtotime($this->appointment->start_time)) : '';
        $endTime = $this->appointment->end_time ? date('g:i A', strtotime($this->appointment->end_time)) : '';
        $timeRange = $startTime && $endTime ? "{$startTime} - {$endTime}" : '';

        $unsubscribeUrl = URL::signedRoute('unsubscribe', ['user' => $notifiable->id], now()->addDays(30));

        return (new MailMessage)
            ->subject("Your {$type} has been confirmed! - InkedIn")
            ->view('mail.booking-accepted', [
                'artistName' => $artistName,
                'type' => $type,
                'date' => $date,
                'timeRange' => $timeRange,
                'description' => $this->appointment->description,
                'inboxUrl' => $inboxUrl,
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        $type = $this->appointment->type === 'consultation' ? 'consultation' : 'appointment';

        return (new FcmMessage(notification: new FcmNotification(
            title: "Your {$type} has been confirmed!",
            body: 'Check your InkedIn inbox for details.',
        )))
            ->data([
                'type' => self::EVENT_TYPE,
                'appointment_id' => (string) $this->appointment->id,
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
            'appointment_id' => $this->appointment->id,
            'type' => $this->appointment->type,
            'artist_id' => $this->appointment->artist_id,
        ];
    }

    public function logExtra(): array
    {
        return [
            'event_type' => self::EVENT_TYPE,
            'sender_id' => $this->appointment->artist_id,
            'sender_type' => \App\Models\User::class,
            'reference_id' => $this->appointment->id,
            'reference_type' => Appointment::class,
        ];
    }
}
