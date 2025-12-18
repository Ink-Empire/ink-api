<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Models\Message;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use File;

class ConversationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $json = File::get("database/seed-data/conversations.json");
        $conversations = json_decode($json, true);

        if (Schema::hasTable('conversations')) {
            foreach ($conversations as $conversationData) {
                // Create the conversation
                $conversation = Conversation::create([
                    'type' => $conversationData['type'],
                    'appointment_id' => $conversationData['appointment_id'],
                ]);

                // Add participants
                foreach ($conversationData['participants'] as $userId) {
                    ConversationParticipant::create([
                        'conversation_id' => $conversation->id,
                        'user_id' => $userId,
                    ]);
                }

                // Create messages
                if (isset($conversationData['messages'])) {
                    $participants = $conversationData['participants'];
                    foreach ($conversationData['messages'] as $index => $messageData) {
                        // Recipient is the other participant
                        $recipientId = $messageData['sender_id'] === $participants[0]
                            ? $participants[1]
                            : $participants[0];

                        Message::create([
                            'conversation_id' => $conversation->id,
                            'sender_id' => $messageData['sender_id'],
                            'recipient_id' => $recipientId,
                            'content' => $messageData['content'],
                            'type' => $messageData['type'] ?? 'text',
                            'metadata' => isset($messageData['metadata']) ? $messageData['metadata'] : null,
                            'created_at' => now()->subMinutes(count($conversationData['messages']) - $index),
                            'updated_at' => now()->subMinutes(count($conversationData['messages']) - $index),
                        ]);
                    }
                }
            }
        }
    }
}
