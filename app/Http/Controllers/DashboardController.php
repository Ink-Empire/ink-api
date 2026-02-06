<?php

namespace App\Http\Controllers;

use App\Enums\UserTypes;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\Dashboard\AppointmentDashboardResource;
use App\Http\Resources\Dashboard\ArtistDashboardResource;
use App\Http\Resources\Dashboard\StatsDashboardResource;
use App\Http\Resources\Dashboard\StudioDashboardResource;
use App\Http\Resources\Dashboard\SuggestedArtistDashboardResource;
use App\Http\Resources\Dashboard\WishlistArtistDashboardResource;
use App\Models\Studio;
use App\Models\User;
use App\Services\DashboardService;
use App\Util\ModelLookup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardService $dashboardService
    ) {
    }

    /**
     * Get complete dashboard data for a studio.
     * Combines: studio details, artists, announcements, stats, and working hours.
     */
    public function getStudioDashboard(Request $request, int $id): JsonResponse
    {
        $studio = Studio::with(['image', 'address', 'announcements', 'business_hours'])->find($id);

        if (!$studio) {
            return response()->json(['error' => 'Studio not found'], 404);
        }

        // Validate that the authenticated user is the studio owner
        $user = $request->user();
        if (!$user || $studio->owner_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $data = $this->dashboardService->getStudioDashboardData($studio);

        // Attach dashboard data to studio for the resource
        $studio->setAttribute('dashboard_artists', $data['artists']);
        $studio->setAttribute('dashboard_stats', $data['stats']);
        $studio->setAttribute('dashboard_working_hours', $data['working_hours']);

        return response()->json(new StudioDashboardResource($studio));
    }

    /**
     * Get dashboard statistics for a studio.
     */
    public function getStudioStats(Request $request, int $id): JsonResponse
    {
        $studio = Studio::find($id);

        if (!$studio) {
            return response()->json(['error' => 'Studio not found'], 404);
        }

        // Validate that the authenticated user is the studio owner
        $user = $request->user();
        if (!$user || $studio->owner_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $stats = $this->dashboardService->getStudioStatsData($studio);

        return response()->json(new StatsDashboardResource($stats));
    }

    /**
     * Get complete dashboard data for an artist.
     */
    public function getArtistDashboard(Request $request, $id): JsonResponse
    {
        $artist = ModelLookup::findArtist($id);

        if (!$artist) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        // Validate that the authenticated user is the artist
        $user = $request->user();
        if (!$user || $user->id !== $artist->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $data = $this->dashboardService->getArtistDashboardData($artist);

        return response()->json(['data' => $data]);
    }

    /**
     * Get dashboard statistics for an artist.
     */
    public function getArtistStats(Request $request, $id): JsonResponse
    {
        $artist = ModelLookup::findArtist($id);

        if (!$artist) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        // Validate that the authenticated user is the artist
        $user = $request->user();
        if (!$user || $user->id !== $artist->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $stats = $this->dashboardService->getArtistStatsData($artist);

        return response()->json(['data' => $stats]);
    }

    /**
     * Get upcoming schedule for an artist.
     */
    public function getArtistSchedule(Request $request, $id): JsonResponse
    {
        $artist = ModelLookup::findArtist($id);

        if (!$artist) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        // Validate that the authenticated user is the artist
        $user = $request->user();
        if (!$user || $user->id !== $artist->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $schedule = $this->dashboardService->getArtistUpcomingSchedule($artist);

        return response()->json(['data' => $schedule]);
    }

    // ==================== Client Dashboard Methods ====================

    /**
     * Get aggregated dashboard data for a client.
     * Includes favorites to avoid separate API call.
     */
    public function getClientDashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $this->dashboardService->getClientDashboardData($user);

        return response()->json([
            'appointments' => AppointmentDashboardResource::collection($data['appointments']),
            'conversations' => ConversationResource::collection($data['conversations']),
            'favorites' => ArtistDashboardResource::collection($data['favorites']),
            'wishlist_count' => $data['wishlist_count'],
            'suggested_artists' => SuggestedArtistDashboardResource::collection($data['suggested_artists']),
        ]);
    }

    /**
     * Get wishlist artists for the authenticated client.
     */
    public function getClientWishlist(Request $request): JsonResponse
    {
        $user = $request->user();

        $wishlistItems = $this->dashboardService->getClientWishlist($user);

        return response()->json([
            'wishlist' => WishlistArtistDashboardResource::collection($wishlistItems),
        ]);
    }

    /**
     * Get favorited/saved artists for the authenticated client.
     */
    public function getClientFavorites(Request $request): JsonResponse
    {
        $user = $request->user();

        $favoriteArtists = $this->dashboardService->getClientFavorites($user);

        return response()->json([
            'favorites' => ArtistDashboardResource::collection($favoriteArtists),
        ]);
    }

    /**
     * Get saved tattoos for the authenticated client.
     */
    public function getClientSavedTattoos(Request $request): JsonResponse
    {
        $user = $request->user();

        $savedTattoos = $this->dashboardService->getClientSavedTattoos($user);

        return response()->json([
            'tattoos' => $savedTattoos,
        ]);
    }

    /**
     * Add an artist to the wishlist.
     */
    public function addToWishlist(Request $request): JsonResponse
    {
        $request->validate([
            'artist_id' => 'required|exists:users,id',
            'notify_booking_open' => 'boolean',
        ]);

        $user = $request->user();
        $artistId = $request->artist_id;

        // Verify the target is an artist
        $artist = User::where('id', $artistId)
            ->where('type_id', UserTypes::ARTIST_TYPE_ID)
            ->first();

        if (!$artist) {
            return response()->json(['error' => 'Artist not found'], 404);
        }

        // Check if already on wishlist
        if ($this->dashboardService->isOnWishlist($user, $artistId)) {
            return response()->json(['error' => 'Artist already on wishlist'], 409);
        }

        $this->dashboardService->addToWishlist(
            $user,
            $artistId,
            $request->get('notify_booking_open', true)
        );

        return response()->json(['success' => true], 201);
    }

    /**
     * Remove an artist from the wishlist.
     */
    public function removeFromWishlist(Request $request, int $artistId): JsonResponse
    {
        $user = $request->user();

        $deleted = $this->dashboardService->removeFromWishlist($user, $artistId);

        if (!$deleted) {
            return response()->json(['error' => 'Artist not on wishlist'], 404);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Update wishlist notification settings.
     */
    public function updateWishlistItem(Request $request, int $artistId): JsonResponse
    {
        $request->validate([
            'notify_booking_open' => 'required|boolean',
        ]);

        $user = $request->user();

        $updated = $this->dashboardService->updateWishlistItem(
            $user,
            $artistId,
            $request->notify_booking_open
        );

        if (!$updated) {
            return response()->json(['error' => 'Artist not on wishlist'], 404);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Get suggested artists for the authenticated client.
     */
    public function getClientSuggestedArtists(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = $request->get('limit', 6);

        $artists = $this->dashboardService->getSuggestedArtists($user, $limit);

        return response()->json([
            'artists' => SuggestedArtistDashboardResource::collection($artists),
        ]);
    }
}
