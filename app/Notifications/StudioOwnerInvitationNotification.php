<?php

namespace App\Notifications;

use App\Models\StudioInvitation;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StudioOwnerInvitationNotification extends Notification
{
    public const EVENT_TYPE = 'studio_owner_invitation';

    public function __construct(
        public StudioInvitation $invitation,
        public User $invitedBy
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'https://getinked.in');
        $claimUrl = $frontendUrl . '/register?userType=studio&studioSlug=' . $this->invitation->studio->slug;

        return (new MailMessage)
            ->subject('Someone thinks you own ' . $this->invitation->studio->name . ' on InkedIn')
            ->view('mail.studio-owner-invitation', [
                'studioName' => $this->invitation->studio->name,
                'clientName' => $this->invitedBy->name,
                'claimUrl' => $claimUrl,
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'invitation_id' => $this->invitation->id,
            'inviter_id' => $this->invitedBy->id,
        ];
    }

    public function logExtra(): array
    {
        return [
            'event_type' => self::EVENT_TYPE,
            'sender_id' => $this->invitedBy->id,
            'sender_type' => User::class,
            'reference_id' => $this->invitation->id,
            'reference_type' => StudioInvitation::class,
        ];
    }
}
