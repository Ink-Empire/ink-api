<?php

namespace App\Mail;

use App\Models\ArtistInvitation;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ArtistInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public ArtistInvitation $invitation,
        public User $invitedBy
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your tattoo work was shared on InkedIn",
        );
    }

    public function content(): Content
    {
        $frontendUrl = config('app.frontend_url', 'https://getinked.in');
        $claimUrl = $frontendUrl . '/claim/' . $this->invitation->token;

        return new Content(
            view: 'mail.artist-invitation',
            with: [
                'artistName' => $this->invitation->artist_name,
                'clientName' => $this->invitedBy->name,
                'claimUrl' => $claimUrl,
            ],
        );
    }
}
