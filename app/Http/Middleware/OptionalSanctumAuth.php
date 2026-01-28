<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
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
        if ($token = $request->bearerToken()) {
            $accessToken = PersonalAccessToken::findToken($token);

            if ($accessToken && $accessToken->tokenable) {
                // Set the user on the request and Auth guard
                Auth::shouldUse('sanctum');
                Auth::setUser($accessToken->tokenable);
            }
        }

        return $next($request);
    }
}
