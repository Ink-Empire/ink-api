<?php

namespace App\Listeners;

use App\Models\DeviceToken;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Support\Facades\Log;

class CleanupFailedFcmToken
{
    public function handle(NotificationFailed $event): void
    {
        if ($event->channel !== 'fcm') {
            return;
        }

        $token = $event->data['token'] ?? null;

        if ($token) {
            DeviceToken::where('token', $token)->delete();
            Log::info('Removed invalid FCM token', ['token' => substr($token, 0, 20) . '...']);
        }
    }
}
