<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Appointment;
use App\Models\Image;
use App\Services\WatermarkService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    public function __construct(
        protected WatermarkService $watermarkService
    ) {
    }
    /**
     * Get messages for a specific appointment
     */
    public function getMessages(Request $request, $appointmentId): JsonResponse
    {
        $appointment = Appointment::findOrFail($appointmentId);
        
        // Ensure user is either the artist or client for this appointment
        $userId = Auth::id();
        if ($appointment->artist_id !== $userId && $appointment->client_id !== $userId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Get all messages for this appointment, ordered by creation date
        $messages = Message::forAppointment($appointmentId)
            ->with(['sender', 'recipient', 'parentMessage', 'attachments.image'])
            ->orderBy('created_at', 'asc')
            ->get();

        // Mark messages as read for the current user
        Message::forAppointment($appointmentId)
            ->unreadFor($userId)
            ->update(['read_at' => now()]);

        return response()->json([
            'appointment' => $appointment->load(['client', 'artist']),
            'messages' => $messages
        ]);
    }

    /**
     * Send a new message or reply
     */
    public function sendMessage(Request $request): JsonResponse
    {
        $request->validate([
            'appointment_id' => 'required|exists:appointments,id',
            'content' => 'nullable|string|max:2000',
            'parent_message_id' => 'nullable|exists:messages,id',
            'type' => 'nullable|in:text,image,design_share,booking_card,deposit_request,price_quote',
            'attachments' => 'nullable|array',
            'attachments.*' => 'exists:images,id',
            'metadata' => 'nullable|array',
            'apply_watermark' => 'nullable|boolean',
        ]);

        // Content is required unless there are attachments
        if (!$request->content && empty($request->attachments)) {
            throw ValidationException::withMessages([
                'content' => 'Content is required when no attachments are provided'
            ]);
        }

        $appointment = Appointment::findOrFail($request->appointment_id);
        $senderId = Auth::id();

        // Ensure user is either the artist or client for this appointment
        if ($appointment->artist_id !== $senderId && $appointment->client_id !== $senderId) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        // Determine recipient (opposite of sender)
        $recipientId = $senderId === $appointment->artist_id
            ? $appointment->client_id
            : $appointment->artist_id;

        // If replying to a message, validate parent message belongs to this appointment
        if ($request->parent_message_id) {
            $parentMessage = Message::findOrFail($request->parent_message_id);
            if ($parentMessage->appointment_id !== $appointment->id) {
                throw ValidationException::withMessages([
                    'parent_message_id' => 'Parent message must belong to the same appointment'
                ]);
            }
        }

        // Determine message type
        $type = $request->type ?? 'text';
        if (!$request->type && !empty($request->attachments)) {
            $type = 'image';
        }

        // Create the message
        $message = Message::create([
            'appointment_id' => $request->appointment_id,
            'sender_id' => $senderId,
            'recipient_id' => $recipientId,
            'parent_message_id' => $request->parent_message_id,
            'content' => $request->content ?? '',
            'message_type' => $request->parent_message_id ? 'reply' : 'initial',
            'type' => $type,
            'metadata' => $request->metadata,
        ]);

        // Attach images if provided
        if (!empty($request->attachments)) {
            $applyWatermark = $request->boolean('apply_watermark') && $type === 'design_share';

            foreach ($request->attachments as $imageId) {
                $attachmentImageId = $imageId;

                // Apply watermark if requested and this is a design share
                if ($applyWatermark) {
                    $sourceImage = Image::find($imageId);
                    if ($sourceImage) {
                        $watermarkedImage = $this->watermarkService->applyWatermark($sourceImage, $senderId);
                        if ($watermarkedImage) {
                            $attachmentImageId = $watermarkedImage->id;
                        }
                    }
                }

                MessageAttachment::create([
                    'message_id' => $message->id,
                    'image_id' => $attachmentImageId,
                ]);
            }
        }

        // Load relationships for response
        $message->load(['sender', 'recipient', 'parentMessage', 'attachments.image']);

        return response()->json([
            'message' => $message,
            'success' => 'Message sent successfully'
        ], 201);
    }

    /**
     * Mark message as read
     */
    public function markAsRead(Request $request, $messageId): JsonResponse
    {
        $message = Message::findOrFail($messageId);
        
        // Ensure current user is the recipient
        if ($message->recipient_id !== Auth::id()) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $message->markAsRead();

        return response()->json(['success' => 'Message marked as read']);
    }

    /**
     * Get unread message count for current user
     */
    public function getUnreadCount(Request $request): JsonResponse
    {
        $userId = Auth::id();
        $unreadCount = Message::unreadFor($userId)->count();

        return response()->json(['unread_count' => $unreadCount]);
    }

    /**
     * Get appointment threads with latest messages for artist inbox
     */
    public function getInboxThreads(Request $request): JsonResponse
    {
        $artistId = Auth::id();
        
        // Get appointments with messages for this artist
        $appointments = Appointment::where('artist_id', $artistId)
            ->whereHas('messages')
            ->withMessages()
            ->with(['client', 'latestMessage.sender'])
            ->orderBy('updated_at', 'desc')
            ->get();

        // Add unread count for each appointment
        $appointments->each(function ($appointment) use ($artistId) {
            $appointment->unread_count = $appointment->unreadMessagesFor($artistId)->count();
        });

        return response()->json(['appointments' => $appointments]);
    }
}
