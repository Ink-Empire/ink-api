<?php

/**
 * Every route behind the admin prefix has to actually be closed.
 *
 * These sweep the route table rather than naming endpoints, so a route added
 * to the admin group later is covered without anyone remembering to add a
 * test. A route that answers anything other than 403 to a signed-in
 * non-admin, or 401 to a guest, is reported by name.
 *
 * Nothing here reaches a controller: the middleware rejects the request first,
 * so the destructive admin routes are safe to call.
 */

use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

beforeEach(function () {
    // The user observer notifies Slack on create, which runs inline on the
    // sync queue and reaches out over the network.
    Queue::fake();

    $this->nonAdmin = User::factory()->asArtist()->create(['email_verified_at' => now()]);
});

/**
 * Every route carrying the admin middleware, as [method, path] with route
 * parameters filled in. The value does not matter; the request never gets far
 * enough to look it up.
 */
function adminRoutes(): array
{
    return collect(Route::getRoutes())
        ->filter(fn ($route) => in_array('admin', $route->gatherMiddleware(), true))
        ->map(function ($route) {
            $method = collect($route->methods())->first(fn ($m) => $m !== 'HEAD');

            return [$method, '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri())];
        })
        ->values()
        ->all();
}

test('the route table actually has admin routes to check', function () {
    // Guards the sweeps below: if the filter stops matching, they would pass
    // by checking nothing at all.
    expect(adminRoutes())->not->toBeEmpty();
});

test('every admin route refuses a signed in non admin', function () {
    $allowed = [];

    foreach (adminRoutes() as [$method, $path]) {
        $status = $this->actingAs($this->nonAdmin, 'sanctum')
            ->json($method, $path)
            ->getStatusCode();

        if ($status !== 403) {
            $allowed[] = "{$method} {$path} returned {$status}";
        }
    }

    expect($allowed)->toBeEmpty();
});

test('every admin route refuses a guest', function () {
    $allowed = [];

    foreach (adminRoutes() as [$method, $path]) {
        $status = $this->json($method, $path)->getStatusCode();

        if ($status !== 401) {
            $allowed[] = "{$method} {$path} returned {$status}";
        }
    }

    expect($allowed)->toBeEmpty();
});
