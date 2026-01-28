<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Attempts Sanctum authentication if a Bearer token is present,
 * but allows the request to proceed even if not authenticated.
 * This enables public routes to optionally identify the user.
 */
class OptionalSanctumAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        // Only attempt authentication if a Bearer token is present
        if ($request->bearerToken()) {
            // Try to authenticate via Sanctum - this sets $request->user() if valid
            Auth::shouldUse('sanctum');

            try {
                Auth::authenticate();
            } catch (\Exception $e) {
                // Token invalid/expired - continue as guest
            }
        }

        return $next($request);
    }
}
