<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BookingRequestNotification extends Notification
{
    // Note: Removed ShouldQueue temporarily for testing - add back when queue is configured

    public function __construct(
        public Appointment $appointment
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
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

        return (new MailMessage)
            ->subject("New {$type} request from {$clientName} - InkedIn")
            ->view('mail.booking-request', [
                'clientName' => $clientName,
                'type' => $type,
                'date' => $date,
                'timeRange' => $timeRange,
                'description' => $this->appointment->description,
                'inboxUrl' => $inboxUrl,
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
}
