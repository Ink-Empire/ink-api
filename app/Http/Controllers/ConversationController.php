<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\ConversationService;
use App\Services\WatermarkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    /**
     * Get all conversations for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Get IDs of users this user has blocked or is blocked by
        $allBlockedIds = $user->getAllBlockedIds();

        $query = Conversation::forUser($user->id)
            ->with(['users', 'latestMessage.sender', 'appointment.artist'])
            ->withCount(['messages as unread_count' => function ($q) use ($user) {
                $q->where('sender_id', '!=', $user->id)
                    ->whereRaw('messages.created_at > COALESCE(
                        (SELECT cp.last_read_at FROM conversation_participants cp
                         WHERE cp.conversation_id = messages.conversation_id
                         AND cp.user_id = ?),
                        \'1970-01-01\'
                    )', [$user->id]);
            }]);

        // Filter out conversations with blocked users
        if (!empty($allBlockedIds)) {
            $query->whereDoesntHave('users', function ($q) use ($allBlockedIds) {
                $q->whereIn('users.id', $allBlockedIds);
            });
        }

        // Filter out conversations where the other participant has been deleted
        $query->whereHas('users', function ($q) use ($user) {
            $q->where('users.id', '!=', $user->id);
        });

        // Filter by type
        if ($request->has('type')) {
            $query->ofType($request->type);
        }

        // Filter by unread
        if ($request->boolean('unread')) {
            $query->having('unread_count', '>', 0);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search, $user) {
                $q->whereHas('users', function ($uq) use ($search, $user) {
                    $uq->where('users.id', '!=', $user->id)
                        ->where(function ($sq) use ($search) {
                            $sq->where('name', 'like', "%{$search}%")
                                ->orWhere('username', 'like', "%{$search}%");
                        });
                })->orWhereHas('latestMessage', function ($mq) use ($search) {
                    $mq->where('content', 'like', "%{$search}%");
                });
            });
        }

        $conversations = $query->orderByDesc('updated_at')
            ->paginate($request->get('limit', 20));

        return response()->json([
            'conversations' => ConversationResource::collection($conversations),
            'meta' => [
                'current_page' => $conversations->currentPage(),
                'last_page' => $conversations->lastPage(),
                'per_page' => $conversations->perPage(),
                'total' => $conversations->total(),
            ],
        ]);
    }

    /**
     * Get a single conversation with messages.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $conversation = Conversation::forUser($user->id)
            ->with(['users', 'appointment.artist'])
            ->findOrFail($id);

        // Get paginated messages (oldest first for display)
        $messagesQuery = $conversation->messages()
            ->visibleTo($user->id)
            ->with(['sender', 'attachments.image']);

        // Support cursor pagination for infinite scroll (load older messages)
        if ($request->has('before')) {
            $messagesQuery->where('id', '<', $request->before);
        }

        // Order by created_at ascending (oldest first)
        $messages = $messagesQuery->orderBy('created_at', 'asc')->limit($request->get('limit', 50))->get();

        return response()->json([
            'conversation' => new ConversationResource($conversation),
            'messages' => MessageResource::collection($messages),
        ]);
    }

    /**
     * Create a new conversation.
     */
    public function store(Request $request, ConversationService $conversationService): JsonResponse
    {
        $request->validate([
            'participant_id' => 'required|exists:users,id',
            'type' => 'in:booking,consultation,guest-spot,design',
            'initial_message' => 'nullable|string|max:5000',
            'appointment_id' => 'nullable|exists:appointments,id',
        ]);

        $user = $request->user();
        $participantId = $request->participant_id;

        // Check if either user has blocked the other
        if ($user->hasBlocked($participantId) || $user->isBlockedBy($participantId)) {
            return response()->json(['error' => 'Cannot start conversation with this user'], 403);
        }

        $conversation = $conversationService->findOrCreate(
            $user->id,
            $participantId,
            $request->get('type', 'booking'),
            $request->appointment_id
        );

        $wasRecentlyCreated = $conversation->wasRecentlyCreated;

        // Create initial message if provided (only for new conversations)
        if ($wasRecentlyCreated && $request->initial_message) {
            Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $user->id,
                'recipient_id' => $participantId,
                'content' => $request->initial_message,
                'type' => 'text',
            ]);
        }

        $conversation->load(['users', 'appointment']);

        if (!$wasRecentlyCreated) {
            return response()->json([
                'conversation' => new ConversationResource($conversation),
                'message' => 'Conversation already exists',
            ]);
        }

        return response()->json([
            'conversation' => new ConversationResource($conversation),
        ], 201);
    }

    /**
     * Mark conversation as read for the authenticated user.
     */
    public function markAsRead(Request $request, int $id, ConversationService $conversationService): JsonResponse
    {
        $user = $request->user();

        $participant = \App\Models\ConversationParticipant::where('conversation_id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$participant) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        $conversationService->markAsRead($id, $user->id);

        return response()->json(['success' => true]);
    }

    /**
     * Get messages for a conversation.
     */
    public function getMessages(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        // Verify user is participant
        $conversation = Conversation::forUser($user->id)->findOrFail($id);

        $messagesQuery = $conversation->messages()
            ->visibleTo($user->id)
            ->with(['sender', 'attachments.image']);

        // Cursor pagination (load older messages)
        if ($request->has('before')) {
            $messagesQuery->where('id', '<', $request->before);
        }

        // Fetch only newer messages (for polling)
        if ($request->has('after')) {
            $messagesQuery->where('id', '>', $request->after);
        }

        // Order by created_at ascending (oldest first)
        $messages = $messagesQuery->orderBy('created_at', 'asc')->limit($request->get('limit', 50))->get();

        return response()->json([
            'messages' => MessageResource::collection($messages),
            'has_more' => $messages->count() === (int) $request->get('limit', 50),
        ]);
    }

    /**
     * Send a message in a conversation.
     */
    public function sendMessage(Request $request, int $id, WatermarkService $watermarkService, ConversationService $conversationService): JsonResponse
    {
        $request->validate([
            'content' => 'nullable|string|max:5000',
            'type' => 'in:text,image,design_share,booking_card,deposit_request,price_quote',
            'metadata' => 'nullable|array',
            'attachment_ids' => 'nullable|array',
            'attachment_ids.*' => 'exists:images,id',
        ]);

        // Require content OR attachments
        if (empty($request->content) && empty($request->attachment_ids)) {
            return response()->json(['error' => 'Message content or attachments required'], 422);
        }

        $user = $request->user();

        // Verify user is participant
        $conversation = Conversation::forUser($user->id)->findOrFail($id);

        // Get the other participant
        $otherParticipant = $conversation->getOtherParticipant($user->id);

        // Check if either user has blocked the other
        if ($otherParticipant && ($user->hasBlocked($otherParticipant->id) || $user->isBlockedBy($otherParticipant->id))) {
            return response()->json(['error' => 'Cannot send message to this user'], 403);
        }

        try {
            $message = $conversationService->sendMessage(
                $conversation,
                $user->id,
                $request->content,
                $request->get('type', 'text'),
                $request->metadata,
                $request->attachment_ids,
                $watermarkService
            );

            return response()->json([
                'message' => new MessageResource($message->load(['sender', 'attachments.image'])),
            ], 201);
        } catch (\Exception $e) {
            \Log::error('Failed to send message', [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);
            return response()->json(['error' => 'Failed to send message: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Send a booking card message.
     */
    public function sendBookingCard(Request $request, int $id, ConversationService $conversationService): JsonResponse
    {
        $request->validate([
            'date' => 'required|string',
            'time' => 'required|string',
            'duration' => 'required|string',
            'deposit_amount' => 'required|string',
        ]);

        $user = $request->user();
        $conversation = Conversation::forUser($user->id)->findOrFail($id);

        $message = $conversationService->sendTypedMessage($conversation, $user->id, "Here's the booking details:", 'booking_card', [
            'date' => $request->date,
            'time' => $request->time,
            'duration' => $request->duration,
            'deposit' => $request->deposit_amount,
        ]);

        return response()->json([
            'message' => new MessageResource($message->load('sender')),
        ], 201);
    }

    /**
     * Send a deposit request message.
     */
    public function sendDepositRequest(Request $request, int $id, ConversationService $conversationService): JsonResponse
    {
        $request->validate([
            'amount' => 'required|string',
            'appointment_id' => 'nullable|exists:appointments,id',
        ]);

        $user = $request->user();
        $conversation = Conversation::forUser($user->id)->findOrFail($id);

        $message = $conversationService->sendTypedMessage($conversation, $user->id, 'Deposit request', 'deposit_request', [
            'amount' => $request->amount,
            'appointment_id' => $request->appointment_id,
        ]);

        return response()->json([
            'message' => new MessageResource($message->load('sender')),
        ], 201);
    }

    /**
     * Send a design share message.
     */
    public function sendDesignShare(Request $request, int $id, ConversationService $conversationService): JsonResponse
    {
        $request->validate([
            'tattoo_id' => 'required|exists:tattoos,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();
        $conversation = Conversation::forUser($user->id)->findOrFail($id);

        $message = $conversationService->sendTypedMessage($conversation, $user->id, $request->notes ?? 'Check out this design', 'design_share', [
            'tattoo_id' => $request->tattoo_id,
            'notes' => $request->notes,
        ]);

        return response()->json([
            'message' => new MessageResource($message->load('sender')),
        ], 201);
    }

    /**
     * Send a price quote message.
     */
    public function sendPriceQuote(Request $request, int $id, ConversationService $conversationService): JsonResponse
    {
        $request->validate([
            'items' => 'required|array',
            'items.*.description' => 'required|string',
            'items.*.amount' => 'required|string',
            'total' => 'required|string',
            'valid_until' => 'nullable|string',
            'notes' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();
        $conversation = Conversation::forUser($user->id)->findOrFail($id);

        $message = $conversationService->sendTypedMessage($conversation, $user->id, $request->notes ?? 'Here\'s your quote', 'price_quote', [
            'items' => $request->items,
            'total' => $request->total,
            'valid_until' => $request->valid_until,
        ]);

        return response()->json([
            'message' => new MessageResource($message->load('sender')),
        ], 201);
    }

    /**
     * Get unread count for the authenticated user.
     */
    public function getUnreadCount(Request $request, ConversationService $conversationService): JsonResponse
    {
        $user = $request->user();

        $count = $conversationService->getUnreadCount($user->id);

        return response()->json(['unread_count' => $count]);
    }

    /**
     * Mark all conversations as read for the authenticated user.
     */
    public function markAllAsRead(Request $request, ConversationService $conversationService): JsonResponse
    {
        $conversationService->markAllAsRead($request->user()->id);

        return response()->json(['success' => true]);
    }

    /**
     * Delete a conversation for the authenticated user (hides from their view).
     */
    public function destroy(Request $request, int $id, ConversationService $conversationService): JsonResponse
    {
        $deleted = $conversationService->deleteConversation($id, $request->user()->id);

        if (!$deleted) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Delete a message for the authenticated user (hides from their view).
     */
    public function deleteMessage(Request $request, int $id, int $messageId, ConversationService $conversationService): JsonResponse
    {
        $deleted = $conversationService->deleteMessage($messageId, $request->user()->id);

        if (!$deleted) {
            return response()->json(['error' => 'Message not found'], 404);
        }

        return response()->json(['success' => true]);
    }
}
