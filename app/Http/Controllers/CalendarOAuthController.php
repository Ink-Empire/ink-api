<?php

namespace App\Http\Controllers;

use App\Models\CalendarConnection;
use App\Services\GoogleCalendarService;
use App\Jobs\SyncUserCalendar;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class CalendarOAuthController extends Controller
{
    public function __construct(
        private GoogleCalendarService $googleCalendar
    ) {}

    /**
     * Get the Google OAuth URL
     */
    public function getAuthUrl(Request $request): JsonResponse
    {
        $state = encrypt($request->user()->id);
        $url = $this->googleCalendar->getAuthUrl($state);

        return response()->json(['url' => $url]);
    }

    /**
     * Handle OAuth callback
     */
    public function handleCallback(Request $request): mixed
    {
        try {
            $code = $request->input('code');
            $state = $request->input('state');

            if (!$code) {
                return response()->json(['error' => 'No authorization code provided'], 400);
            }

            if (!$state) {
                return response()->json(['error' => 'Invalid state parameter'], 400);
            }

            // Decrypt user ID from state
            try {
                $userId = decrypt($state);
            } catch (\Exception $e) {
                return response()->json(['error' => 'Invalid state parameter'], 400);
            }

            $user = \App\Models\User::find($userId);
            if (!$user) {
                return response()->json(['error' => 'User not found'], 404);
            }

            // Exchange code for tokens
            $tokens = $this->googleCalendar->exchangeCode($code);

            // Get user info from Google
            $googleUser = $this->googleCalendar->getUserInfo($tokens['access_token']);

            // Create or update calendar connection
            $connection = CalendarConnection::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'provider' => 'google',
                ],
                [
                    'provider_account_id' => $googleUser['id'],
                    'provider_email' => $googleUser['email'],
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'token_expires_at' => now()->addSeconds($tokens['expires_in']),
                    'sync_token' => null, // Reset for fresh sync
                ]
            );

            // Get primary calendar ID
            $this->googleCalendar->initializeWithConnection($connection);
            $calendarId = $this->googleCalendar->getPrimaryCalendarId();
            $connection->update(['calendar_id' => $calendarId]);

            // Queue initial sync
            SyncUserCalendar::dispatch($connection->id);

            // Set up webhook for push notifications (only in production)
            if (config('app.env') === 'production') {
                try {
                    $this->googleCalendar->setupWebhook($connection);
                } catch (\Exception $e) {
                    Log::warning("Failed to set up webhook for connection {$connection->id}: " . $e->getMessage());
                }
            }

            Log::info("Google Calendar connected for user {$user->id} ({$googleUser['email']})");

            // Redirect to frontend calendar page with success param
            $frontendUrl = config('app.frontend_url', 'http://localhost:4000');
            return redirect()->away("{$frontendUrl}/calendar?calendar_connected=true");

        } catch (\Exception $e) {
            Log::error('Google Calendar OAuth error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);

            // Redirect to frontend with error param
            $frontendUrl = config('app.frontend_url', 'http://localhost:4000');
            $errorMessage = urlencode($e->getMessage());
            return redirect()->away("{$frontendUrl}/calendar?calendar_error={$errorMessage}");
        }
    }

    /**
     * Disconnect Google Calendar
     */
    public function disconnect(Request $request): JsonResponse
    {
        $user = $request->user();
        $connection = CalendarConnection::where('user_id', $user->id)
            ->where('provider', 'google')
            ->first();

        if (!$connection) {
            return response()->json(['error' => 'No calendar connected'], 404);
        }

        // Stop webhook
        try {
            $this->googleCalendar->stopWebhook($connection);
        } catch (\Exception $e) {
            Log::warning("Failed to stop webhook during disconnect: " . $e->getMessage());
        }

        // Delete connection and events
        $connection->externalEvents()->delete();
        $connection->delete();

        Log::info("Google Calendar disconnected for user {$user->id}");

        return response()->json(['success' => true]);
    }

    /**
     * Get connection status
     */
    public function status(Request $request): JsonResponse
    {
        $connection = CalendarConnection::where('user_id', $request->user()->id)
            ->where('provider', 'google')
            ->first();

        if (!$connection) {
            return response()->json([
                'connected' => false,
            ]);
        }

        return response()->json([
            'connected' => true,
            'email' => $connection->provider_email,
            'last_synced_at' => $connection->last_synced_at?->toIso8601String(),
            'sync_enabled' => $connection->sync_enabled,
        ]);
    }

    /**
     * Toggle sync on/off
     */
    public function toggleSync(Request $request): JsonResponse
    {
        $connection = CalendarConnection::where('user_id', $request->user()->id)
            ->where('provider', 'google')
            ->first();

        if (!$connection) {
            return response()->json(['error' => 'No calendar connected'], 404);
        }

        $connection->update([
            'sync_enabled' => !$connection->sync_enabled,
        ]);

        return response()->json([
            'sync_enabled' => $connection->sync_enabled,
        ]);
    }

    /**
     * Manually trigger sync
     */
    public function triggerSync(Request $request): JsonResponse
    {
        $connection = CalendarConnection::where('user_id', $request->user()->id)
            ->where('provider', 'google')
            ->first();

        if (!$connection) {
            return response()->json(['error' => 'No calendar connected'], 404);
        }

        if (!$connection->sync_enabled) {
            return response()->json(['error' => 'Sync is disabled'], 400);
        }

        SyncUserCalendar::dispatch($connection->id);

        return response()->json([
            'success' => true,
            'message' => 'Sync started',
        ]);
    }

    /**
     * Get external calendar events for a date range
     */
    public function getEvents(Request $request): JsonResponse
    {
        $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after:start',
        ]);

        $connection = CalendarConnection::where('user_id', $request->user()->id)
            ->where('provider', 'google')
            ->first();

        if (!$connection) {
            return response()->json(['events' => []]);
        }

        $events = $connection->externalEvents()
            ->where('status', '!=', 'cancelled')
            ->where(function ($query) use ($request) {
                $query->whereBetween('starts_at', [$request->start, $request->end])
                    ->orWhereBetween('ends_at', [$request->start, $request->end])
                    ->orWhere(function ($q) use ($request) {
                        $q->where('starts_at', '<=', $request->start)
                            ->where('ends_at', '>=', $request->end);
                    });
            })
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            'events' => $events->map(fn($event) => [
                'id' => $event->id,
                'title' => $event->title,
                'starts_at' => $event->starts_at->toIso8601String(),
                'ends_at' => $event->ends_at->toIso8601String(),
                'all_day' => $event->all_day,
                'source' => $event->source,
            ]),
        ]);
    }
}
