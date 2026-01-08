<?php

namespace App\Http\Controllers;

use App\Models\CalendarConnection;
use App\Jobs\SyncUserCalendar;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class CalendarWebhookController extends Controller
{
    /**
     * Handle Google Calendar push notification
     */
    public function handleGoogleWebhook(Request $request): Response
    {
        // Google sends these headers
        $channelId = $request->header('X-Goog-Channel-ID');
        $resourceState = $request->header('X-Goog-Resource-State');
        $resourceId = $request->header('X-Goog-Resource-ID');

        Log::debug("Received Google Calendar webhook", [
            'channel_id' => $channelId,
            'resource_state' => $resourceState,
            'resource_id' => $resourceId,
        ]);

        if (!$channelId) {
            Log::warning("Received Google Calendar webhook without channel ID");
            return response('Missing channel ID', 400);
        }

        // Ignore sync messages (initial setup confirmation)
        if ($resourceState === 'sync') {
            Log::debug("Received sync confirmation for channel {$channelId}");
            return response('OK', 200);
        }

        // Find the connection
        $connection = CalendarConnection::where('webhook_channel_id', $channelId)->first();

        if (!$connection) {
            Log::warning("Received webhook for unknown channel: {$channelId}");
            return response('Unknown channel', 404);
        }

        // Throttle: max 1 sync per minute per connection
        $cacheKey = "calendar_webhook_throttle:{$connection->id}";
        if (Cache::has($cacheKey)) {
            Log::debug("Throttled webhook for connection {$connection->id}");
            return response('OK', 200);
        }

        Cache::put($cacheKey, true, 60);

        // Queue sync job
        SyncUserCalendar::dispatch($connection->id);

        Log::info("Queued calendar sync from webhook for connection {$connection->id}");

        return response('OK', 200);
    }
}
