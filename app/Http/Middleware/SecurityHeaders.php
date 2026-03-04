<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /**
     * Handle an incoming request and add security headers to the response.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Skip strict CSP for dashboards that need JS/CSS to render
        if ($request->is('horizon', 'horizon/*', 'mailbook', 'mailbook/*')) {
            return $response;
        }

        // Prevent MIME type sniffing
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // Prevent clickjacking
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');

        // Enable XSS filter in older browsers
        $response->headers->set('X-XSS-Protection', '1; mode=block');

        // Referrer policy - send origin only for cross-origin requests
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

        // Permissions policy - disable unnecessary browser features
        $response->headers->set('Permissions-Policy', 'geolocation=(self), camera=(), microphone=()');

        // Content Security Policy for API responses
        // Note: This is a basic policy for API responses. The frontend should have its own CSP.
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

        return $response;
    }
}
