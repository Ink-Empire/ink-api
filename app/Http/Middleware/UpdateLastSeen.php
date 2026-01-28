<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UpdateLastSeen
{
    /**
     * Handle an incoming request.
     *
     * Updates the authenticated user's last_seen_at timestamp.
     * Throttles updates to once per minute to reduce database writes.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Update last_seen_at after the response (non-blocking)
        $user = $request->user();

        if ($user) {
            // Only update if last_seen_at is null or older than the throttle period
            $throttleMinutes = config('app.last_seen_throttle_minutes', 1);
            $shouldUpdate = !$user->last_seen_at ||
                            $user->last_seen_at->lt(now()->subMinutes($throttleMinutes));

            if ($shouldUpdate) {
                $user->updateLastSeen();
            }
        }

        return $response;
    }
}
