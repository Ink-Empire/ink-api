<?php

namespace App\Notifications;

use App\Models\BulkUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InboundEmailReceiptNotification extends Notification
{
    use Queueable;

    public const EVENT_TYPE = 'inbound_email_receipt';

    public function __construct(
        public BulkUpload $bulkUpload,
        public int $photoCount,
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
        $reviewUrl = $frontendUrl . '/dashboard/uploads/' . $this->bulkUpload->id;

        $subject = $this->isNewAccount
            ? "Your photos are ready — activate your InkedIn account"
            : "Your {$this->photoCount} " . str('photo')->plural($this->photoCount) . " arrived on InkedIn";

        return (new MailMessage)
            ->subject($subject)
            ->view('mail.inbound-email-receipt', [
                'photoCount'   => $this->photoCount,
                'reviewUrl'    => $reviewUrl,
                'isNewAccount' => $this->isNewAccount,
                'userName'     => $notifiable->name,
                'userEmail'    => $notifiable->email,
                'tempPassword' => $this->tempPassword,
                'loginUrl'     => $frontendUrl . '/login',
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
            'reference_id' => $this->bulkUpload->id,
            'reference_type' => BulkUpload::class,
        ];
    }
}
