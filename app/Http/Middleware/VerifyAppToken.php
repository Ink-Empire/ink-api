<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyAppToken
{
    /**
     * Routes that should be excluded from app token verification.
     * These are typically webhook endpoints called by external services.
     */
    protected array $except = [
        'api/webhooks/*',
        'api/calendar/callback',
        'sanctum/csrf-cookie',
    ];

    /**
     * Handle an incoming request.
     * Validates that requests include a valid app token to prevent unauthorized API access.
     * Skips validation when a Bearer token is present (user auth takes precedence).
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip verification for excluded routes
        if ($this->shouldSkip($request)) {
            return $next($request);
        }

        // Skip app token verification if Bearer token is present
        // User authentication via Bearer token is sufficient for user-specific actions
        $authHeader = $request->header('Authorization');
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return $next($request);
        }

        $configuredToken = config('app.api_app_token');

        // If no token is configured, skip verification (for local development)
        if (empty($configuredToken)) {
            return $next($request);
        }

        $providedToken = $request->header('X-App-Token');

        if (empty($providedToken) || !hash_equals($configuredToken, $providedToken)) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid or missing app token',
            ], 401);
        }

        return $next($request);
    }

    /**
     * Determine if the request should skip app token verification.
     */
    protected function shouldSkip(Request $request): bool
    {
        foreach ($this->except as $pattern) {
            if ($request->is($pattern)) {
                return true;
            }
        }

        return false;
    }
}
