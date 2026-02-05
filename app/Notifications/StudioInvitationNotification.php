<?php

namespace App\Notifications;

use App\Models\Studio;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Notifications\Traits\RespectsEmailPreferences;

class StudioInvitationNotification extends Notification
{
    use RespectsEmailPreferences;

    public const EVENT_TYPE = 'studio_invitation';

    public function __construct(
        public Studio $studio,
        public ?User $invitedBy = null
    ) {}

    public function via(object $notifiable): array
    {
        return $this->filterChannelsForUnsubscribed($notifiable, ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $dashboardUrl = $frontendUrl . '/dashboard';

        $studioName = $this->studio->name ?? 'A studio';
        $inviterName = $this->invitedBy?->name ?? $this->studio->owner?->name ?? 'The studio owner';

        return (new MailMessage)
            ->subject("You've been invited to join {$studioName} - InkedIn")
            ->view('mail.studio-invitation', [
                'studioName' => $studioName,
                'inviterName' => $inviterName,
                'studioLocation' => $this->studio->location,
                'dashboardUrl' => $dashboardUrl,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'studio_id' => $this->studio->id,
            'studio_name' => $this->studio->name,
            'invited_by' => $this->invitedBy?->id,
        ];
    }

    public function logExtra(): array
    {
        return [
            'event_type' => self::EVENT_TYPE,
            'sender_id' => $this->invitedBy?->id ?? $this->studio->owner_id,
            'sender_type' => User::class,
            'reference_id' => $this->studio->id,
            'reference_type' => Studio::class,
        ];
    }
}
