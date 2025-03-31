<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return string|null
     */
    protected function redirectTo($request)
    {
        if (! $request->expectsJson()) {
            // For an API-only application, return a 401 response instead of redirecting to login
            abort(401, 'Unauthenticated');
            // Or if you want to have a login page, uncomment below and define a login route
            // return '/login';
        }
    }
}
