<?php

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use App\Notifications\Traits\RespectsEmailPreferences;

class GuestAppointmentInviteNotification extends Notification
{
    use RespectsEmailPreferences;

    public const EVENT_TYPE = 'guest_appointment_invite';

    public function __construct(
        public Appointment $appointment,
        public ?string $setupToken = null
    ) {}

    public function via(object $notifiable): array
    {
        return $this->filterChannelsForUnsubscribed($notifiable, ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'https://getinked.in');

        $artistName = $this->appointment->artist?->name ?? $this->appointment->artist?->username ?? 'Your artist';
        $type = $this->appointment->type === 'consultation' ? 'consultation' : 'appointment';
        $date = $this->appointment->date?->format('F j, Y') ?? 'TBD';
        $startTime = $this->appointment->start_time ? date('g:i A', strtotime($this->appointment->start_time)) : '';

        // New guests get a set-password link so they can claim the account
        // created for them; existing users go straight to their appointments.
        $actionUrl = $this->setupToken
            ? $frontendUrl . '/reset-password?token=' . $this->setupToken . '&email=' . urlencode($notifiable->email)
            : $frontendUrl . '/appointments';

        $unsubscribeUrl = URL::signedRoute('unsubscribe', ['user' => $notifiable->id], now()->addDays(30));

        return (new MailMessage)
            ->subject("{$artistName} scheduled a {$type} with you - InkedIn")
            ->view('mail.guest-appointment-invite', [
                'artistName' => $artistName,
                'type' => $type,
                'date' => $date,
                'startTime' => $startTime,
                'note' => $this->appointment->description,
                'actionUrl' => $actionUrl,
                'isNewAccount' => $this->setupToken !== null,
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'appointment_id' => $this->appointment->id,
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
