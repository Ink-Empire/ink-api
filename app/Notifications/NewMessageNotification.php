<?php

namespace App\Notifications;

use App\Models\Message;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use App\Notifications\Traits\RespectsEmailPreferences;

class NewMessageNotification extends Notification
{
    use RespectsEmailPreferences;

    public const EVENT_TYPE = 'new_message';

    public function __construct(
        public Message $message,
        public User $sender
    ) {}

    public function via(object $notifiable): array
    {
        return $this->filterChannelsForUnsubscribed($notifiable, ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $inboxUrl = $frontendUrl . '/inbox';

        $senderName = $this->sender->name ?? $this->sender->first_name ?? 'Someone';

        return (new MailMessage)
            ->subject("New message from {$senderName} - InkedIn")
            ->view('mail.new-message', [
                'senderName' => $senderName,
                'inboxUrl' => $inboxUrl,
                'recipientName' => $notifiable->first_name ?? $notifiable->name ?? 'there',
            ]);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'message_id' => $this->message->id,
            'conversation_id' => $this->message->conversation_id,
            'sender_id' => $this->sender->id,
        ];
    }

    public function logExtra(): array
    {
        return [
            'event_type' => self::EVENT_TYPE,
            'sender_id' => $this->sender->id,
            'sender_type' => User::class,
            'reference_id' => $this->message->id,
            'reference_type' => Message::class,
        ];
    }
}
