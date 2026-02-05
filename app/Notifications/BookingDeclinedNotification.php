<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Notifications\Traits\RespectsEmailPreferences;

class BookingDeclinedNotification extends Notification
{
    use RespectsEmailPreferences;

    public const EVENT_TYPE = 'booking_declined';

    public function __construct(
        public Appointment $appointment,
        public ?string $reason = null
    ) {}

    public function via(object $notifiable): array
    {
        return $this->filterChannelsForUnsubscribed($notifiable, ['mail']);
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

        return (new MailMessage)
            ->subject("Your {$type} request update - InkedIn")
            ->view('mail.booking-declined', [
                'artistName' => $artistName,
                'type' => $type,
                'date' => $date,
                'timeRange' => $timeRange,
                'reason' => $this->reason,
                'inboxUrl' => $inboxUrl,
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
