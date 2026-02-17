<?php

namespace App\Services;

use App\Enums\UserTypes;
use App\Jobs\SendSlackSupportNotification;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Image;
use App\Models\Message;
use App\Models\MessageDeletion;
use App\Models\User;
use App\Notifications\NewMessageNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConversationService
{
    /**
     * Find an existing conversation between two users, or create a new one.
     */
    public function findOrCreate(int $userId, int $participantId, string $type = 'booking', ?int $appointmentId = null): Conversation
    {
        $existing = Conversation::whereHas('participants', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->whereHas('participants', function ($q) use ($participantId) {
                $q->where('user_id', $participantId);
            })
            ->first();

        if ($existing) {
            if ($appointmentId && !$existing->appointment_id) {
                $existing->update(['appointment_id' => $appointmentId]);
            }

            return $existing;
        }

        return DB::transaction(function () use ($userId, $participantId, $type, $appointmentId) {
            $conversation = Conversation::create([
                'type' => $type,
                'appointment_id' => $appointmentId,
            ]);

            ConversationParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $userId,
            ]);

            ConversationParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $participantId,
            ]);

            return $conversation;
        });
    }

    /**
     * Mark a conversation as read for a given user.
     */
    public function markAsRead(int $conversationId, int $userId): void
    {
        $participant = ConversationParticipant::where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->first();

        if ($participant) {
            $participant->markAsRead();
        }

        Message::where('conversation_id', $conversationId)
            ->where('recipient_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        Cache::forget("unread_count:{$userId}");
    }

    /**
     * Send a message in a conversation.
     */
    public function sendMessage(
        Conversation $conversation,
        int $senderId,
        ?string $content,
        string $type = 'text',
        ?array $metadata = null,
        ?array $attachmentIds = null,
        ?WatermarkService $watermarkService = null
    ): Message {
        return DB::transaction(function () use ($conversation, $senderId, $content, $type, $metadata, $attachmentIds, $watermarkService) {
            $otherParticipant = $conversation->getOtherParticipant($senderId);

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $senderId,
                'recipient_id' => $otherParticipant?->id,
                'content' => $content,
                'type' => $type,
                'metadata' => $metadata,
            ]);

            if ($attachmentIds && $watermarkService) {
                $sender = \App\Models\User::find($senderId);
                $isArtist = $sender && $sender->type_id === UserTypes::ARTIST_TYPE_ID;
                $shouldWatermark = in_array($type, ['design_share', 'image']) && $isArtist;

                foreach ($attachmentIds as $imageId) {
                    $finalImageId = $imageId;

                    if ($shouldWatermark) {
                        $sourceImage = Image::find($imageId);
                        if ($sourceImage) {
                            $watermarkedImage = $watermarkService->applyWatermark($sourceImage, $senderId);
                            if ($watermarkedImage) {
                                $finalImageId = $watermarkedImage->id;
                            }
                        }
                    }

                    $message->attachments()->create(['image_id' => $finalImageId]);
                }
            } elseif ($attachmentIds) {
                foreach ($attachmentIds as $imageId) {
                    $message->attachments()->create(['image_id' => $imageId]);
                }
            }

            $conversation->touch();

            // Clear cached unread count BEFORE sending notification
            // so the notification picks up the fresh count including this new message
            Cache::forget("unread_count:{$otherParticipant?->id}");

            if ($otherParticipant) {
                try {
                    $otherParticipant->notify(new NewMessageNotification($message, User::find($senderId)));
                } catch (\Exception $e) {
                    \Log::warning('Failed to send new message notification', [
                        'recipient_id' => $otherParticipant->id,
                        'error' => $e->getMessage(),
                    ]);
                }

                // Slack notification when someone messages the support account
                $supportUser = User::where('email', 'info@getinked.in')->first();
                if ($supportUser && $otherParticipant->id === $supportUser->id) {
                    SendSlackSupportNotification::dispatch($senderId);
                }
            }

            return $message;
        });
    }

    /**
     * Send a typed message (booking_card, deposit_request, design_share, price_quote).
     */
    public function sendTypedMessage(Conversation $conversation, int $senderId, string $content, string $type, array $metadata): Message
    {
        $otherParticipant = $conversation->getOtherParticipant($senderId);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $senderId,
            'recipient_id' => $otherParticipant?->id,
            'content' => $content,
            'type' => $type,
            'metadata' => $metadata,
        ]);

        $conversation->touch();
        Cache::forget("unread_count:{$otherParticipant?->id}");

        return $message;
    }

    /**
     * Get the total unread message count for a user across all conversations.
     */
    public function getUnreadCount(int $userId): int
    {
        return Cache::remember("unread_count:{$userId}", 60, function () use ($userId) {
            $result = DB::selectOne("
            SELECT COUNT(*) as total
            FROM messages m
            INNER JOIN conversation_participants cp
                ON cp.conversation_id = m.conversation_id
                AND cp.user_id = ?
                AND cp.deleted_at IS NULL
            WHERE m.sender_id != ?
              AND m.created_at > COALESCE(cp.last_read_at, '1970-01-01')
        ", [$userId, $userId]);

            return (int) ($result->total ?? 0);
        });
    }

    /**
     * Mark all conversations as read for a user.
     */
    public function markAllAsRead(int $userId): void
    {
        $now = now();

        ConversationParticipant::where('user_id', $userId)
            ->whereNull('deleted_at')
            ->update(['last_read_at' => $now]);

        Message::where('recipient_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => $now]);

        Cache::forget("unread_count:{$userId}");
    }

    /**
     * Delete a conversation for a specific user (hide from their view).
     */
    public function deleteConversation(int $conversationId, int $userId): bool
    {
        $participant = ConversationParticipant::where('conversation_id', $conversationId)
            ->where('user_id', $userId)
            ->first();

        if (!$participant) {
            return false;
        }

        $participant->update(['deleted_at' => now()]);

        return true;
    }

    /**
     * Delete a message for a specific user (hide from their view).
     */
    public function deleteMessage(int $messageId, int $userId): bool
    {
        $message = Message::where('id', $messageId)
            ->whereHas('conversation.participants', function ($q) use ($userId) {
                $q->where('user_id', $userId);
            })
            ->first();

        if (!$message) {
            return false;
        }

        MessageDeletion::firstOrCreate([
            'message_id' => $messageId,
            'user_id' => $userId,
        ]);

        return true;
    }


    /**
     * Apply the unread count subquery using a join instead of a correlated subquery.
     * This resolves last_read_at once via join rather than per-row.
     */
    private function withUnreadCount(Builder $query, int $userId): Builder
    {
        return $query
            ->leftJoinSub(
                DB::table('conversation_participants')
                    ->select('conversation_id', 'last_read_at')
                    ->where('user_id', $userId),
                'cp_unread',
                'cp_unread.conversation_id',
                'conversations.id'
            )
            ->withCount(['messages as unread_count' => function ($q) use ($userId) {
                $q->where('sender_id', '!=', $userId)
                    ->whereColumn('messages.created_at', '>', DB::raw("COALESCE(cp_unread.last_read_at, '1970-01-01')"));
            }]);
    }
}
