<?php

namespace App\Http\Middleware;

use App\Models\Studio;
use App\Services\StudioService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a held studio off its public page and out of everything nested under
 * it, so the hold is not defeated by a direct link.
 *
 * Applied to the public studio reads as a group rather than checked in each
 * controller method: there are nine of them, and one that forgot the check
 * would serve the whole page's content anyway.
 *
 * The owner and an admin still get through. A held owner can sign in and keep
 * working on their page - they may be entirely legitimate and mid-setup - and
 * the dashboard preview reads the same public endpoint.
 */
class BlockHeldStudio
{
    public function __construct(protected StudioService $studioService)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $studio = $this->studioService->getById($request->route('id'));

        if ($studio && $studio->isOnHold() && ! $this->mayView($request, $studio)) {
            return response()->json([
                'success' => false,
                'message' => 'Studio not found',
            ], 404);
        }

        return $next($request);
    }

    /**
     * These routes sit outside auth:sanctum, so a bearer token is resolved the
     * same way OptionalSanctumAuth does it. The auth guard is deliberately not
     * switched: this only needs to know who is asking.
     */
    private function mayView(Request $request, Studio $studio): bool
    {
        $user = $request->user();

        if (! $user && $token = $request->bearerToken()) {
            $user = PersonalAccessToken::findToken($token)?->tokenable;
        }

        if (! $user) {
            return false;
        }

        return (bool) $user->is_admin || (int) $studio->owner_id === (int) $user->id;
    }
}
