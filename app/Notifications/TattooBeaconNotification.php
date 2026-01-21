<?php

namespace App\Notifications;

use App\Models\TattooLead;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TattooBeaconNotification extends Notification
{
    use Queueable;

    public const EVENT_TYPE = 'beacon_request';

    public function __construct(
        public TattooLead $lead,
        public User $client
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:4000');
        $leadsUrl = $frontendUrl . '/dashboard/leads';

        $clientName = $this->client->name ?? $this->client->username;
        $location = $this->client->location ?? 'your area';
        $timing = $this->getTimingLabel($this->lead->timing);

        return (new MailMessage)
            ->subject("Someone near you is looking for a tattoo! - InkedIn")
            ->view('mail.tattoo-beacon', [
                'clientName' => $clientName,
                'location' => $location,
                'timing' => $timing,
                'description' => $this->lead->description,
                'leadsUrl' => $leadsUrl,
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
