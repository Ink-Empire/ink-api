<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use App\Notifications\Traits\RespectsEmailPreferences;

class BookingRequestNotification extends Notification
{
    use RespectsEmailPreferences;

    // Note: Removed ShouldQueue temporarily for testing - add back when queue is configured

    public const EVENT_TYPE = 'booking_request';

    public function __construct(
        public Appointment $appointment
    ) {}

    public function via(object $notifiable): array
    {
        return $this->filterChannelsForUnsubscribed($notifiable, ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        \Log::info('BookingRequestNotification::toMail() called');

        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $inboxUrl = $frontendUrl . '/dashboard/inbox';

        $clientName = $this->appointment->client?->name ?? 'A client';
        \Log::info('Building email for client: ' . $clientName);
        $type = $this->appointment->type === 'consultation' ? 'consultation' : 'appointment';
        $date = $this->appointment->date?->format('F j, Y') ?? 'TBD';
        $startTime = $this->appointment->start_time ? date('g:i A', strtotime($this->appointment->start_time)) : '';
        $endTime = $this->appointment->end_time ? date('g:i A', strtotime($this->appointment->end_time)) : '';
        $timeRange = $startTime && $endTime ? "{$startTime} - {$endTime}" : '';

        $unsubscribeUrl = URL::signedRoute('unsubscribe', ['user' => $notifiable->id], now()->addDays(30));

        return (new MailMessage)
            ->subject("New {$type} request from {$clientName} - InkedIn")
            ->view('mail.booking-request', [
                'clientName' => $clientName,
                'type' => $type,
                'date' => $date,
                'timeRange' => $timeRange,
                'description' => $this->appointment->description,
                'inboxUrl' => $inboxUrl,
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'appointment_id' => $this->appointment->id,
            'type' => $this->appointment->type,
            'client_id' => $this->appointment->client_id,
        ];
    }

    public function logExtra(): array
    {
        return [
            'event_type' => self::EVENT_TYPE,
            'sender_id' => $this->appointment->client_id,
            'sender_type' => \App\Models\User::class,
            'reference_id' => $this->appointment->id,
            'reference_type' => Appointment::class,
        ];
    }
}
