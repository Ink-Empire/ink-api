<?php

namespace App\Notifications;

use App\Models\CalendarConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CalendarDisconnectedNotification extends Notification
{
    use Queueable;

    public const EVENT_TYPE = 'calendar_disconnected';

    public function __construct(
        public CalendarConnection $calendarConnection
    ) {}

    /**
     * Transactional. The artist's calendar has stopped syncing and only they
     * can reconnect it, so this ignores email preferences.
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:4000');

        return (new MailMessage)
            ->subject('Your calendar has disconnected - InkedIn')
            ->view('mail.calendar-disconnected', [
                'name' => $notifiable->name ?? $notifiable->username,
                'providerEmail' => $this->calendarConnection->provider_email,
                'reconnectUrl' => $frontendUrl.'/dashboard/settings/calendar',
            ]);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => self::EVENT_TYPE,
            'message' => 'Your Google Calendar disconnected and needs reconnecting.',
            'entity_type' => 'calendar_connection',
            'entity_id' => $this->calendarConnection->id,
        ];
    }
}
