<?php

namespace App\Http\Controllers;

use App\Jobs\SendSlackSupportNotification;
use App\Models\User;
use App\Services\ConversationService;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    public function getContact()
    {
        $user = User::where('email', 'info@getinked.in')->first();

        return response()->json(['user_id' => $user?->id]);
    }

    public function sendMessage(Request $request, ConversationService $conversationService)
    {
        $request->validate([
            'message' => 'nullable|string|max:2000',
        ]);

        $sender = $request->user();
        $message = $request->input('message');

        // Always fire Slack notification regardless of whether the support user exists
        SendSlackSupportNotification::dispatch($sender->id, $message);

        // Try to create conversation and send message if the support user exists
        $supportUser = User::where('email', 'info@getinked.in')->first();
        $conversationId = null;

        if ($supportUser) {
            $conversation = $conversationService->findOrCreate($sender->id, $supportUser->id, 'consultation');
            $conversationId = $conversation->id;

            if ($message) {
                $conversationService->sendMessage($conversation, $sender->id, $message);
            }
        }

        return response()->json([
            'notified' => true,
            'conversation_id' => $conversationId,
        ]);
    }
}
