<?php

namespace App\Http\Controllers;

use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    /**
     * Get all conversations for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Conversation::forUser($user->id)
            ->with(['users', 'latestMessage.sender', 'appointment.artist'])
            ->withCount(['messages as unread_count' => function ($q) use ($user) {
                $q->where('sender_id', '!=', $user->id)
                    ->where(function ($sq) use ($user) {
                        $sq->whereDoesntHave('conversation.participants', function ($pq) use ($user) {
                            $pq->where('user_id', $user->id)
                                ->whereNotNull('last_read_at')
                                ->whereColumn('last_read_at', '>=', 'messages.created_at');
                        });
                    });
            }]);

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

        $conversations = $query->orderByDesc(
            Message::select('created_at')
                ->whereColumn('conversation_id', 'conversations.id')
                ->orderByDesc('created_at')
                ->limit(1)
        )->paginate($request->get('limit', 20));

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
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'participant_id' => 'required|exists:users,id',
            'type' => 'in:booking,consultation,guest-spot,design',
            'initial_message' => 'nullable|string|max:5000',
            'appointment_id' => 'nullable|exists:appointments,id',
        ]);

        $user = $request->user();
        $participantId = $request->participant_id;

        // Check if conversation already exists between these users
        $existingConversation = Conversation::forUser($user->id)
            ->whereHas('participants', function ($q) use ($participantId) {
                $q->where('user_id', $participantId);
            })
            ->when($request->appointment_id, function ($q, $appointmentId) {
                $q->where('appointment_id', $appointmentId);
            })
            ->first();

        if ($existingConversation) {
            return response()->json([
                'conversation' => new ConversationResource($existingConversation->load(['users', 'appointment'])),
                'message' => 'Conversation already exists',
            ]);
        }

        DB::beginTransaction();
        try {
            // Create conversation
            $conversation = Conversation::create([
                'type' => $request->get('type', 'booking'),
                'appointment_id' => $request->appointment_id,
            ]);

            // Add participants
            ConversationParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $user->id,
            ]);

            ConversationParticipant::create([
                'conversation_id' => $conversation->id,
                'user_id' => $participantId,
            ]);

            // Create initial message if provided
            if ($request->initial_message) {
                Message::create([
                    'conversation_id' => $conversation->id,
                    'sender_id' => $user->id,
                    'recipient_id' => $participantId,
                    'content' => $request->initial_message,
                    'type' => 'text',
                ]);
            }

            DB::commit();

            return response()->json([
                'conversation' => new ConversationResource($conversation->load(['users', 'appointment'])),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to create conversation'], 500);
        }
    }

    /**
     * Mark conversation as read for the authenticated user.
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $participant = ConversationParticipant::where('conversation_id', $id)
            ->where('user_id', $user->id)
            ->first();

        if (!$participant) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        // Update conversation participant's last_read_at
        $participant->markAsRead();

        // Mark individual messages as read (messages sent TO this user)
        Message::where('conversation_id', $id)
            ->where('recipient_id', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

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
            ->with(['sender', 'attachments.image']);

        // Cursor pagination (load older messages)
        if ($request->has('before')) {
            $messagesQuery->where('id', '<', $request->before);
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
    public function sendMessage(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'content' => 'required_without:attachment_ids|string|max:5000',
            'type' => 'in:text,image,booking_card,deposit_request',
            'metadata' => 'nullable|array',
            'attachment_ids' => 'nullable|array',
            'attachment_ids.*' => 'exists:images,id',
        ]);

        $user = $request->user();

        // Verify user is participant
        $conversation = Conversation::forUser($user->id)->findOrFail($id);

        DB::beginTransaction();
        try {
            // Get the other participant as recipient
            $otherParticipant = $conversation->getOtherParticipant($user->id);

            $message = Message::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $user->id,
                'recipient_id' => $otherParticipant?->id,
                'content' => $request->content,
                'type' => $request->get('type', 'text'),
                'metadata' => $request->metadata,
            ]);

            // Add attachments
            if ($request->attachment_ids) {
                foreach ($request->attachment_ids as $imageId) {
                    $message->attachments()->create(['image_id' => $imageId]);
                }
            }

            // Update conversation updated_at
            $conversation->touch();

            DB::commit();

            return response()->json([
                'message' => new MessageResource($message->load(['sender', 'attachments.image'])),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to send message'], 500);
        }
    }

    /**
     * Send a booking card message.
     */
    public function sendBookingCard(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'date' => 'required|string',
            'time' => 'required|string',
            'duration' => 'required|string',
            'deposit_amount' => 'required|string',
        ]);

        $user = $request->user();
        $conversation = Conversation::forUser($user->id)->findOrFail($id);
        $otherParticipant = $conversation->getOtherParticipant($user->id);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'recipient_id' => $otherParticipant?->id,
            'content' => "Here's the booking details:",
            'type' => 'booking_card',
            'metadata' => [
                'date' => $request->date,
                'time' => $request->time,
                'duration' => $request->duration,
                'deposit' => $request->deposit_amount,
            ],
        ]);

        $conversation->touch();

        return response()->json([
            'message' => new MessageResource($message->load('sender')),
        ], 201);
    }

    /**
     * Send a deposit request message.
     */
    public function sendDepositRequest(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'amount' => 'required|string',
            'appointment_id' => 'nullable|exists:appointments,id',
        ]);

        $user = $request->user();
        $conversation = Conversation::forUser($user->id)->findOrFail($id);
        $otherParticipant = $conversation->getOtherParticipant($user->id);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'recipient_id' => $otherParticipant?->id,
            'content' => 'Deposit request',
            'type' => 'deposit_request',
            'metadata' => [
                'amount' => $request->amount,
                'appointment_id' => $request->appointment_id,
            ],
        ]);

        $conversation->touch();

        return response()->json([
            'message' => new MessageResource($message->load('sender')),
        ], 201);
    }

    /**
     * Send a design share message.
     */
    public function sendDesignShare(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'tattoo_id' => 'required|exists:tattoos,id',
            'notes' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();
        $conversation = Conversation::forUser($user->id)->findOrFail($id);
        $otherParticipant = $conversation->getOtherParticipant($user->id);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'recipient_id' => $otherParticipant?->id,
            'content' => $request->notes ?? 'Check out this design',
            'type' => 'design_share',
            'metadata' => [
                'tattoo_id' => $request->tattoo_id,
                'notes' => $request->notes,
            ],
        ]);

        $conversation->touch();

        return response()->json([
            'message' => new MessageResource($message->load('sender')),
        ], 201);
    }

    /**
     * Send a price quote message.
     */
    public function sendPriceQuote(Request $request, int $id): JsonResponse
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
        $otherParticipant = $conversation->getOtherParticipant($user->id);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'sender_id' => $user->id,
            'recipient_id' => $otherParticipant?->id,
            'content' => $request->notes ?? 'Here\'s your quote',
            'type' => 'price_quote',
            'metadata' => [
                'items' => $request->items,
                'total' => $request->total,
                'valid_until' => $request->valid_until,
            ],
        ]);

        $conversation->touch();

        return response()->json([
            'message' => new MessageResource($message->load('sender')),
        ], 201);
    }

    /**
     * Get unread count for the authenticated user.
     */
    public function getUnreadCount(Request $request): JsonResponse
    {
        $user = $request->user();

        $count = Conversation::forUser($user->id)
            ->withCount(['messages as unread_count' => function ($q) use ($user) {
                $q->where('sender_id', '!=', $user->id)
                    ->whereDoesntHave('conversation.participants', function ($pq) use ($user) {
                        $pq->where('user_id', $user->id)
                            ->whereNotNull('last_read_at')
                            ->whereColumn('last_read_at', '>=', 'messages.created_at');
                    });
            }])
            ->get()
            ->sum('unread_count');

        return response()->json(['unread_count' => $count]);
    }
}
