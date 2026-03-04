<?php

namespace App\Notifications;

use App\Models\ArtistInvitation;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ArtistInvitationNotification extends Notification
{
    public const EVENT_TYPE = 'artist_invitation';

    public function __construct(
        public ArtistInvitation $invitation,
        public User $invitedBy
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'https://getinked.in');
        $claimUrl = $frontendUrl . '/claim/' . $this->invitation->token;

        return (new MailMessage)
            ->subject('Your tattoo work was shared on InkedIn')
            ->view('mail.artist-invitation', [
                'artistName' => $this->invitation->artist_name,
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
            'reference_type' => ArtistInvitation::class,
        ];
    }
}
