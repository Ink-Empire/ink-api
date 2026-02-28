<?php

namespace App\Http\Controllers;

use App\Http\Resources\BriefImageResource;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\ConversationService;
use App\Services\WatermarkService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class ConversationController extends Controller
{
    /**
     * Get all conversations for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = $user->id;
        $allBlockedIds = $user->getAllBlockedIds();

        $query = Conversation::forUser($userId)
            ->with(['users', 'latestMessage.sender', 'appointment.artist']);

        $this->withUnreadCount($query, $userId);

        // Filter out conversations with blocked users
        if (!empty($allBlockedIds)) {
            $query->whereDoesntHave('users', fn ($q) => $q->whereIn('users.id', $allBlockedIds));
        }

        // Filter out conversations where the other participant has been deleted
        $query->whereHas('users', fn ($q) => $q->where('users.id', '!=', $userId));

        // Filter by type
        if ($request->has('type')) {
            $query->ofType($request->type);
        }

        // Filter by unread
        if ($request->boolean('unread')) {
            $query->having('unread_count', '>', 0);
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search, $userId) {
                $q->whereHas('users', function ($uq) use ($search, $userId) {
                    $uq->where('users.id', '!=', $userId)
                        ->where(fn ($sq) => $sq->where('name', 'like', "%{$search}%")
                            ->orWhere('username', 'like', "%{$search}%"));
                })->orWhereHas('latestMessage', fn ($mq) => $mq->where('content', 'like', "%{$search}%"));
            });
        }

        $conversations = $query
            ->orderByDesc('updated_at')
            ->paginate($request->integer('limit', 20));

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
        $userId = $user->id;

        $query = Conversation::forUser($userId)
            ->with(['users', 'appointment.artist']);

        $this->withUnreadCount($query, $userId);

        $conversation = $query->findOrFail($id);

        // Cursor-based message loading (oldest first for display)
        $messagesQuery = $conversation->messages()
            ->visibleTo($userId)
            ->with(['sender', 'attachments.image']);

        if ($request->has('before')) {
            $messagesQuery->where('messages.id', '<', $request->integer('before'));
        }

        $limit = $request->integer('limit', 50);
        $messages = $messagesQuery
            ->orderBy('messages.created_at', 'asc')
            ->limit($limit)
            ->get();

        return response()->json([
            'conversation' => new ConversationResource($conversation),
            'messages' => MessageResource::collection($messages),
            'has_more' => $messages->count() === $limit,
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

        $exists = DB::table('conversation_participants')
            ->where('conversation_id', $id)
            ->where('user_id', $user->id)
            ->exists();

        if (!$exists) {
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
        $userId = $user->id;

        // Verify user is participant
        $conversation = Conversation::forUser($userId)->findOrFail($id);

        $messagesQuery = $conversation->messages()
            ->visibleTo($userId)
            ->with(['sender', 'attachments.image']);

        // Cursor pagination (load older messages)
        if ($request->has('before')) {
            $messagesQuery->where('messages.id', '<', $request->integer('before'));
        }

        // Fetch only newer messages (for polling)
        if ($request->has('after')) {
            $messagesQuery->where('messages.id', '>', $request->integer('after'));
        }

        $limit = $request->integer('limit', 50);
        $messages = $messagesQuery
            ->orderBy('messages.created_at', 'asc')
            ->limit($limit)
            ->get();

        return response()->json([
            'messages' => MessageResource::collection($messages),
            'has_more' => $messages->count() === $limit,
        ]);
    }

    /**
     * Send a message in a conversation.
     */
    public function sendMessage(Request $request, int $id, WatermarkService $watermarkService, ConversationService $conversationService): JsonResponse
    {
        $request->validate([
            'content' => 'nullable|string|max:5000',
            'type' => 'in:text,image,design_share,booking_card,deposit_request,price_quote,cancellation,reschedule',
            'metadata' => 'nullable|array',
            'attachment_ids' => 'nullable|array',
            'attachment_ids.*' => 'exists:images,id',
        ]);

        if (empty($request->content) && empty($request->attachment_ids)) {
            return response()->json(['error' => 'Message content or attachments required'], 422);
        }

        $user = $request->user();
        $conversation = Conversation::forUser($user->id)->findOrFail($id);
        $otherParticipant = $conversation->getOtherParticipant($user->id);

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
     * Send a typed message (booking card, deposit request, etc).
     */
    private function sendTypedMessageResponse(
        Request $request,
        int $id,
        ConversationService $conversationService,
        string $content,
        string $type,
        array $metadata
    ): JsonResponse {
        $user = $request->user();
        $conversation = Conversation::forUser($user->id)->findOrFail($id);

        $message = $conversationService->sendTypedMessage($conversation, $user->id, $content, $type, $metadata);

        return response()->json([
            'message' => new MessageResource($message->load('sender')),
        ], 201);
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

        return $this->sendTypedMessageResponse($request, $id, $conversationService, "Here's the booking details:", 'booking_card', [
            'date' => $request->date,
            'time' => $request->time,
            'duration' => $request->duration,
            'deposit' => $request->deposit_amount,
        ]);
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

        return $this->sendTypedMessageResponse($request, $id, $conversationService, 'Deposit request', 'deposit_request', [
            'amount' => $request->amount,
            'appointment_id' => $request->appointment_id,
        ]);
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

        return $this->sendTypedMessageResponse($request, $id, $conversationService, $request->notes ?? 'Check out this design', 'design_share', [
            'tattoo_id' => $request->tattoo_id,
            'notes' => $request->notes,
        ]);
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

        return $this->sendTypedMessageResponse($request, $id, $conversationService, $request->notes ?? 'Here\'s your quote', 'price_quote', [
            'items' => $request->items,
            'total' => $request->total,
            'valid_until' => $request->valid_until,
        ]);
    }

    /**
     * Send a cancellation message and cancel the appointment.
     */
    public function sendCancellationRequest(Request $request, int $id, ConversationService $conversationService): JsonResponse
    {
        $request->validate([
            'appointment_id' => 'required|exists:appointments,id',
            'reason' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();
        $conversation = Conversation::forUser($user->id)->findOrFail($id);

        $appointment = Appointment::findOrFail($request->appointment_id);

        // Verify the user is the artist or client on this appointment
        if ($appointment->artist_id !== $user->id && $appointment->client_id !== $user->id) {
            return response()->json(['error' => 'You are not authorized to cancel this appointment'], 403);
        }

        // Cancel the appointment
        $appointment->update(['status' => 'cancelled']);

        // Clear artist schedule cache
        Cache::forget("artist:{$appointment->artist_id}:upcoming-schedule");
        Cache::forget("artist:{$appointment->artist_id}:dashboard-stats");

        // Update the original booking card message status to 'cancelled'
        $bookingMessage = $conversation->messages()
            ->where('type', 'booking_card')
            ->whereJsonContains('metadata->appointment_id', (int) $request->appointment_id)
            ->first();

        if ($bookingMessage) {
            $metadata = $bookingMessage->metadata ?? [];
            $metadata['status'] = 'cancelled';
            $bookingMessage->update(['metadata' => $metadata]);
        }

        $content = $request->reason
            ? "Appointment cancelled: {$request->reason}"
            : 'Appointment cancelled';

        $message = $conversationService->sendTypedMessage($conversation, $user->id, $content, 'cancellation', [
            'appointment_id' => $request->appointment_id,
            'reason' => $request->reason,
            'date' => $appointment->date?->toDateString(),
            'start_time' => $appointment->start_time,
            'end_time' => $appointment->end_time,
            'title' => $appointment->title,
        ]);

        return response()->json([
            'message' => new MessageResource($message->load('sender')),
        ], 201);
    }

    /**
     * Send a reschedule request message.
     */
    public function sendRescheduleRequest(Request $request, int $id, ConversationService $conversationService): JsonResponse
    {
        $request->validate([
            'appointment_id' => 'required|exists:appointments,id',
            'proposed_date' => 'required|date',
            'proposed_start_time' => 'required|string',
            'proposed_end_time' => 'required|string',
            'reason' => 'nullable|string|max:1000',
        ]);

        $user = $request->user();
        $conversation = Conversation::forUser($user->id)->findOrFail($id);

        $appointment = Appointment::findOrFail($request->appointment_id);

        if ($appointment->artist_id !== $user->id && $appointment->client_id !== $user->id) {
            return response()->json(['error' => 'You are not authorized to reschedule this appointment'], 403);
        }

        $content = $request->reason
            ? "Reschedule requested: {$request->reason}"
            : 'Reschedule requested';

        $message = $conversationService->sendTypedMessage($conversation, $user->id, $content, 'reschedule', [
            'appointment_id' => $request->appointment_id,
            'proposed_date' => $request->proposed_date,
            'proposed_start_time' => $request->proposed_start_time,
            'proposed_end_time' => $request->proposed_end_time,
            'reason' => $request->reason,
            'status' => 'pending',
        ]);

        return response()->json([
            'message' => new MessageResource($message->load('sender')),
        ], 201);
    }

    /**
     * Respond to a reschedule request (accept/decline).
     */
    public function respondToReschedule(Request $request, int $id, int $messageId, ConversationService $conversationService): JsonResponse
    {
        $request->validate([
            'action' => 'required|in:accept,decline',
        ]);

        $user = $request->user();
        $conversation = Conversation::forUser($user->id)->findOrFail($id);

        $message = Message::where('id', $messageId)
            ->where('conversation_id', $id)
            ->where('type', 'reschedule')
            ->firstOrFail();

        $metadata = $message->metadata ?? [];

        if (($metadata['status'] ?? null) !== 'pending') {
            return response()->json(['error' => 'This reschedule request has already been responded to'], 422);
        }

        // Verify the responder is the client (not the sender of the reschedule)
        if ($message->sender_id === $user->id) {
            return response()->json(['error' => 'You cannot respond to your own reschedule request'], 403);
        }

        $action = $request->action;

        if ($action === 'accept') {
            $appointment = Appointment::findOrFail($metadata['appointment_id']);
            $appointment->update([
                'date' => $metadata['proposed_date'],
                'start_time' => $metadata['proposed_start_time'],
                'end_time' => $metadata['proposed_end_time'],
            ]);

            // Clear artist schedule cache
            Cache::forget("artist:{$appointment->artist_id}:upcoming-schedule");
            Cache::forget("artist:{$appointment->artist_id}:dashboard-stats");

            $metadata['status'] = 'accepted';
        } else {
            $metadata['status'] = 'declined';

            $appointment = Appointment::find($metadata['appointment_id']);
            if ($appointment) {
                $appointment->update(['status' => 'pending']);
                Cache::forget("artist:{$appointment->artist_id}:upcoming-schedule");
                Cache::forget("artist:{$appointment->artist_id}:dashboard-stats");
            }
        }

        $message->update(['metadata' => $metadata]);

        // Send a notification message back
        $responseContent = $action === 'accept'
            ? 'Reschedule accepted'
            : 'Reschedule declined';

        $conversationService->sendTypedMessage($conversation, $user->id, $responseContent, 'text', []);

        return response()->json([
            'message' => new MessageResource($message->fresh()->load('sender')),
        ]);
    }

    /**
     * Get unread count for the authenticated user.
     */
    public function getUnreadCount(Request $request, ConversationService $conversationService): JsonResponse
    {
        return response()->json([
            'unread_count' => $conversationService->getUnreadCount($request->user()->id),
        ]);
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
        if (!$conversationService->deleteConversation($id, $request->user()->id)) {
            return response()->json(['error' => 'Conversation not found'], 404);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Delete a message for the authenticated user (hides from their view).
     */
    public function deleteMessage(Request $request, int $id, int $messageId, ConversationService $conversationService): JsonResponse
    {
        if (!$conversationService->deleteMessage($messageId, $request->user()->id)) {
            return response()->json(['error' => 'Message not found'], 404);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Search users by username, email, or name for starting conversations.
     */
    public function searchUsers(Request $request): JsonResponse
    {
        $query = trim($request->input('q', ''));
        if (strlen($query) < 2) {
            return response()->json(['users' => []]);
        }

        $currentUser = $request->user();
        $likeQuery = '%' . $query . '%';

        $users = User::with('image')
            ->where('id', '!=', $currentUser->id)
            ->where(fn ($q) => $q->where('username', 'like', $likeQuery)
                ->orWhere('email', 'like', $likeQuery)
                ->orWhere('name', 'like', $likeQuery))
            ->limit(10)
            ->get()
            ->map(fn ($user) => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'slug' => $user->slug,
                'image' => $user->image ? new BriefImageResource($user->image) : null,
            ]);

        return response()->json(['users' => $users]);
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
