<?php

namespace App\Notifications;

use App\Models\Studio;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Asks a studio owner to establish that they run the business.
 *
 * Sent when an admin places a studio on hold. The recipient may well be a
 * legitimate shop owner, so the tone is a verification request and nothing
 * more: no accusation, and no reference to any other account.
 */
class StudioVerificationRequestNotification extends Notification
{
    public const EVENT_TYPE = 'studio_verification_request';

    public function __construct(public Studio $studio)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject('Confirming your studio listing on InkedIn')
            ->view('mail.studio-verification-request', [
                'studioName' => $this->studio->name,
                'replyTo' => $this->replyAddress(),
            ]);

        // Replies are the proof route, so they have to land in the mailbox
        // that is actually monitored rather than the transactional sender.
        if ($this->replyAddress()) {
            $message->replyTo($this->replyAddress());
        }

        return $message;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'studio_id' => $this->studio->id,
        ];
    }

    public function logExtra(): array
    {
        return [
            'event_type' => self::EVENT_TYPE,
            'reference_id' => $this->studio->id,
            'reference_type' => Studio::class,
        ];
    }

    private function replyAddress(): ?string
    {
        return config('services.inbound_imap.username')
            ?: config('mail.from.address');
    }
}
