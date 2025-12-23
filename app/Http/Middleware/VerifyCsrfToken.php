<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array<int, string>
     */
    /**
     * All API routes are excluded from CSRF verification since they use
     * Bearer token authentication instead of session-based auth.
     */
    protected $except = [
        'api/*',
        '/api/*',
    ];
}
