<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when an inbound email arrived but none of its attachments could be
 * stored.
 *
 * Without this the artist hears nothing at all. onboard() creates the account
 * before it tries the images, and the temp password it generates is only ever
 * handed over by InboundEmailReceiptNotification. Skipping that mail leaves
 * somebody holding an account they do not know about, with a password nobody
 * will ever know, and their address taken by the unique constraint so a later
 * signup fails.
 *
 * HEIC is the common case. iPhones have shot it by default since 2017 and
 * getimagesizefromstring cannot read it, so an artist emailing photos straight
 * from their phone lands here.
 */
class InboundEmailUnreadableNotification extends Notification
{
    use Queueable;

    public const EVENT_TYPE = 'inbound_email_unreadable';

    public function __construct(
        public int $attemptedCount,
        public bool $isNewAccount,
        public ?string $tempPassword = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:4000');

        return (new MailMessage)
            ->subject("We couldn't open your photos")
            ->view('mail.inbound-email-unreadable', [
                'attemptedCount' => $this->attemptedCount,
                'isNewAccount'   => $this->isNewAccount,
                'userName'       => $notifiable->name,
                'userEmail'      => $notifiable->email,
                'tempPassword'   => $this->tempPassword,
                'loginUrl'       => $frontendUrl . '/login',
                'uploadUrl'      => $frontendUrl . '/dashboard',
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }

    public function logExtra(): array
    {
        return [
            'event_type' => self::EVENT_TYPE,
        ];
    }
}
