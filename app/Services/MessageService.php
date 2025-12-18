<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Message;

class MessageService
{
    /**
     * Create a system message in a conversation.
     */
    public function createSystemMessage(
        int $conversationId,
        string $type,
        string $content,
        array $metadata = []
    ): Message {
        $conversation = Conversation::findOrFail($conversationId);
        $participants = $conversation->users->pluck('id')->toArray();

        // System messages don't have a sender, but we need recipient for notifications
        // Use the first participant as a fallback
        $recipientId = $participants[0] ?? null;

        $message = Message::create([
            'conversation_id' => $conversationId,
            'sender_id' => null,
            'recipient_id' => $recipientId,
            'content' => $content,
            'type' => $type,
            'metadata' => $metadata,
        ]);

        $conversation->touch();

        return $message;
    }

    /**
     * Send appointment confirmed notification.
     */
    public function sendAppointmentConfirmed(
        int $conversationId,
        int $appointmentId,
        string $date,
        string $time
    ): Message {
        return $this->createSystemMessage(
            $conversationId,
            'appointment_confirmed',
            'Appointment confirmed!',
            [
                'appointment_id' => $appointmentId,
                'date' => $date,
                'time' => $time,
            ]
        );
    }

    /**
     * Send appointment cancelled notification.
     */
    public function sendAppointmentCancelled(
        int $conversationId,
        int $appointmentId,
        string $reason = null
    ): Message {
        return $this->createSystemMessage(
            $conversationId,
            'appointment_cancelled',
            'Appointment has been cancelled.',
            [
                'appointment_id' => $appointmentId,
                'reason' => $reason,
            ]
        );
    }

    /**
     * Send appointment reminder.
     */
    public function sendAppointmentReminder(
        int $conversationId,
        int $appointmentId,
        string $date,
        string $time,
        string $reminderType = '24h'
    ): Message {
        $content = match ($reminderType) {
            '24h' => 'Reminder: Your appointment is tomorrow!',
            '1h' => 'Reminder: Your appointment is in 1 hour!',
            'week' => 'Reminder: Your appointment is in one week.',
            default => 'Appointment reminder',
        };

        return $this->createSystemMessage(
            $conversationId,
            'appointment_reminder',
            $content,
            [
                'appointment_id' => $appointmentId,
                'date' => $date,
                'time' => $time,
                'reminder_type' => $reminderType,
            ]
        );
    }

    /**
     * Send deposit received notification.
     */
    public function sendDepositReceived(
        int $conversationId,
        string $amount,
        int $appointmentId = null
    ): Message {
        return $this->createSystemMessage(
            $conversationId,
            'deposit_received',
            "Deposit of {$amount} received. Thank you!",
            [
                'amount' => $amount,
                'appointment_id' => $appointmentId,
            ]
        );
    }

    /**
     * Send aftercare instructions.
     */
    public function sendAftercare(
        int $conversationId,
        int $senderId,
        array $instructions,
        string $pdfUrl = null
    ): Message {
        $conversation = Conversation::findOrFail($conversationId);
        $otherParticipant = $conversation->getOtherParticipant($senderId);

        return Message::create([
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'recipient_id' => $otherParticipant?->id,
            'content' => 'Here are your aftercare instructions',
            'type' => 'aftercare',
            'metadata' => [
                'instructions' => $instructions,
                'pdf_url' => $pdfUrl,
            ],
        ]);
    }

    /**
     * Send a generic system notification.
     */
    public function sendSystemNotification(
        int $conversationId,
        string $content,
        array $metadata = []
    ): Message {
        return $this->createSystemMessage(
            $conversationId,
            'system',
            $content,
            $metadata
        );
    }
}
