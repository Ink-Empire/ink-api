<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ConversationUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $conversationId;

    public Message $message;

    public int $userId;

    public int $unreadCount;

    public function __construct(int $conversationId, Message $message, int $userId, int $unreadCount)
    {
        $this->conversationId = $conversationId;
        $this->message = $message;
        $this->userId = $userId;
        $this->unreadCount = $unreadCount;
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->userId . '.conversations'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversationId,
            'last_message' => [
                'id' => $this->message->id,
                'content' => $this->message->content,
                'type' => $this->message->type ?? 'text',
                'sender_id' => $this->message->sender_id,
                'created_at' => $this->message->created_at->toISOString(),
            ],
            'unread_count' => $this->unreadCount,
        ];
    }
}
