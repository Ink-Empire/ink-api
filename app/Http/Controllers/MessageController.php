<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
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
            ->with(['sender', 'recipient', 'parentMessage'])
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
            'content' => 'required|string|max:2000',
            'parent_message_id' => 'nullable|exists:messages,id'
        ]);

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

        // Create the message
        $message = Message::create([
            'appointment_id' => $request->appointment_id,
            'sender_id' => $senderId,
            'recipient_id' => $recipientId,
            'parent_message_id' => $request->parent_message_id,
            'content' => $request->content,
            'message_type' => $request->parent_message_id ? 'reply' : 'initial'
        ]);

        // Load relationships for response
        $message->load(['sender', 'recipient', 'parentMessage']);

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
