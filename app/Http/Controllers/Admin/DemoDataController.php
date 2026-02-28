<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DemoDataController extends Controller
{
    /**
     * Preview demo user booking data that would be deleted.
     */
    public function preview(Request $request): JsonResponse
    {
        $demoUsers = User::where('is_demo', true)->get(['id', 'name', 'username', 'type_id']);

        if ($demoUsers->isEmpty()) {
            return response()->json([
                'demo_users' => [],
                'appointments' => 0,
                'conversations' => 0,
                'messages' => 0,
                'conversation_participants' => 0,
            ]);
        }

        $demoIds = $demoUsers->pluck('id')->toArray();

        $appointments = DB::table('appointments')
            ->where(function ($q) use ($demoIds) {
                $q->whereIn('client_id', $demoIds)
                  ->orWhereIn('artist_id', $demoIds);
            })
            ->count();

        // Conversations where any participant is a demo user
        $conversationIds = DB::table('conversation_participants')
            ->whereIn('user_id', $demoIds)
            ->pluck('conversation_id')
            ->unique()
            ->toArray();

        $messages = DB::table('messages')
            ->whereIn('conversation_id', $conversationIds)
            ->count();

        $participants = DB::table('conversation_participants')
            ->whereIn('conversation_id', $conversationIds)
            ->count();

        return response()->json([
            'demo_users' => $demoUsers->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
            ]),
            'appointments' => $appointments,
            'conversations' => count($conversationIds),
            'messages' => $messages,
            'conversation_participants' => $participants,
        ]);
    }

    /**
     * Delete all booking/messaging data for demo users.
     */
    public function purge(Request $request): JsonResponse
    {
        $demoUsers = User::where('is_demo', true)->pluck('id')->toArray();

        if (empty($demoUsers)) {
            return response()->json(['message' => 'No demo users found.', 'deleted' => []]);
        }

        // Find all conversations involving demo users
        $conversationIds = DB::table('conversation_participants')
            ->whereIn('user_id', $demoUsers)
            ->pluck('conversation_id')
            ->unique()
            ->toArray();

        // Find all appointments involving demo users
        $appointmentIds = DB::table('appointments')
            ->where(function ($q) use ($demoUsers) {
                $q->whereIn('client_id', $demoUsers)
                  ->orWhereIn('artist_id', $demoUsers);
            })
            ->pluck('id')
            ->toArray();

        $deleted = DB::transaction(function () use ($conversationIds, $appointmentIds, $demoUsers) {
            $counts = [];

            // 1. Messages in conversations
            $counts['messages'] = DB::table('messages')
                ->whereIn('conversation_id', $conversationIds)
                ->delete();

            // 2. Messages linked directly by appointment_id (not via conversation)
            if (!empty($appointmentIds)) {
                $counts['messages'] += DB::table('messages')
                    ->whereIn('appointment_id', $appointmentIds)
                    ->whereNotIn('conversation_id', $conversationIds)
                    ->delete();
            }

            // 3. Conversation participants
            $counts['conversation_participants'] = DB::table('conversation_participants')
                ->whereIn('conversation_id', $conversationIds)
                ->delete();

            // 4. Conversations
            $counts['conversations'] = DB::table('conversations')
                ->whereIn('id', $conversationIds)
                ->delete();

            // 5. Appointments
            $counts['appointments'] = DB::table('appointments')
                ->whereIn('id', $appointmentIds)
                ->delete();

            return $counts;
        });

        return response()->json([
            'message' => 'Demo user data purged successfully.',
            'demo_user_ids' => $demoUsers,
            'deleted' => $deleted,
        ]);
    }
}
