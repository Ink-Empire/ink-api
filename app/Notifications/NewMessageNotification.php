<?php

namespace App\Notifications;

use App\Models\Message;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;
use App\Notifications\Traits\RespectsEmailPreferences;
use App\Notifications\Traits\RespectsPushPreferences;

class NewMessageNotification extends Notification
{
    use RespectsEmailPreferences, RespectsPushPreferences;

    public const EVENT_TYPE = 'new_message';

    public function __construct(
        public Message $message,
        public User $sender
    ) {}

    public function via(object $notifiable): array
    {
        $channels = $this->filterChannelsForUnsubscribed($notifiable, ['mail', FcmChannel::class]);

        return $this->filterChannelsForPush($notifiable, $channels);
    }

    public function getMessageCount(object $notifiable): int
    {
        try {
            $conversationService = app(ConversationService::class);
            return $conversationService->getUnreadCount($notifiable->id);
        } catch (\Exception $e) {
            \Log::error("Unable to get message count", [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return 0;
        }
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $inboxUrl = $frontendUrl . '/inbox';

        $senderName = $this->sender->name ?? $this->sender->first_name ?? 'Someone';

        $unsubscribeUrl = URL::signedRoute('unsubscribe', ['user' => $notifiable->id], now()->addDays(30));

        return (new MailMessage)
            ->subject("New message from {$senderName} - InkedIn")
            ->view('mail.new-message', [
                'senderName' => $senderName,
                'inboxUrl' => $inboxUrl,
                'recipientName' => $notifiable->first_name ?? $notifiable->name ?? 'there',
                'unsubscribeUrl' => $unsubscribeUrl,
            ]);
    }

    public function toFcm(object $notifiable): FcmMessage
    {
        $senderName = $this->sender->name ?? $this->sender->username ?? 'Someone';
        $count = $this->getMessageCount($notifiable);

        return (new FcmMessage(notification: new FcmNotification(
            title: "New message from {$senderName}",
            body: 'You have a new message on InkedIn.',
        )))
            ->data([
                'type' => self::EVENT_TYPE,
                'conversation_id' => (string) $this->message->conversation_id,
                'sender_id' => (string) $this->sender->id,
            ])
            ->custom([
                'apns' => [
                    'payload' => [
                        'aps' => [
                            'sound' => 'default',
                            'badge' => $count,
                        ],
                    ],
                ],
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
