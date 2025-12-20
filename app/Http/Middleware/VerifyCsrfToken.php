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
    protected $except = [
        '/tattoos/*',
        'tattoos',
        '/api/tattoos/*',
        '/api/tattoos',
        'elastic',
        'artists',
        '/api/artists',
        '/api/artists/*',
        '/users/*',
        '/artists/*',
        '/images/*',
        '/studios/*',
        '/styles/*',
        '/api/elastic/*',
        '/api/artists/appointments/*',
        '/api/artists/appointments',
        '/api/appointments/*',
        '/api/appointments',
        '/api/appointments/inbox',
        'api/appointments/*',
        'api/appointments',
        'api/appointments/inbox',
        '/api/messages/*',
        '/api/messages',
        '/api/conversations',
        '/api/conversations/*',
        'api/conversations',
        'api/conversations/*',
        '/api/client',
        '/api/client/*',
        'api/client',
        'api/client/*',
        '/api/login',
        '/api/register',
        '/api/logout',
        '/api/username',
        '/api/users/favorites/tattoo'
    ];
}
