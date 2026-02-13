<?php

namespace App\Http\Controllers;

use App\Models\NotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationPreferenceController extends Controller
{
    private const NOTIFICATION_TYPES = [
        'new_message',
        'booking_request',
        'booking_accepted',
        'booking_declined',
        'books_open',
        'beacon_request',
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $prefs = $user->notificationPreferences()
            ->where('channel', 'push')
            ->get()
            ->keyBy('notification_type');

        $result = [];
        foreach (self::NOTIFICATION_TYPES as $type) {
            $result[] = [
                'type' => $type,
                'push_enabled' => isset($prefs[$type]) ? $prefs[$type]->enabled : true,
            ];
        }

        return response()->json(['preferences' => $result]);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'preferences' => 'required|array',
            'preferences.*' => 'boolean',
        ]);

        $user = $request->user();

        foreach ($request->preferences as $type => $enabled) {
            if (!in_array($type, self::NOTIFICATION_TYPES)) {
                continue;
            }

            NotificationPreference::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'notification_type' => $type,
                    'channel' => 'push',
                ],
                ['enabled' => $enabled]
            );
        }

        return $this->index($request);
    }
}
