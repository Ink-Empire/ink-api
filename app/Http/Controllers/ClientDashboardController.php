<?php

namespace App\Http\Controllers;

use App\Enums\UserTypes;
use App\Http\Resources\ClientDashboardAppointmentResource;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\DashboardArtistResource;
use App\Http\Resources\SuggestedArtistResource;
use App\Http\Resources\WishlistArtistResource;
use App\Models\Artist;
use App\Models\ArtistWishlist;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientDashboardController extends Controller
{
    /**
     * Get aggregated dashboard data for a client.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        // Upcoming appointments (next 5, sorted by date)
        $appointments = $user->appointmentsWithStatus('booked')
            ->where('date', '>=', now()->toDateString())
            ->with(['artist.image', 'studio'])
            ->orderBy('date')
            ->orderBy('start_time')
            ->take(5)
            ->get();

        // Recent conversations (last 3) - excluding conversations with deleted users
        $conversations = Conversation::forUser($user->id)
            ->with(['users', 'latestMessage.sender'])
            ->whereHas('users', function ($q) use ($user) {
                $q->where('users.id', '!=', $user->id);
            })
            ->withCount(['messages as unread_count' => function ($q) use ($user) {
                $q->where('sender_id', '!=', $user->id)
                    ->whereDoesntHave('conversation.participants', function ($pq) use ($user) {
                        $pq->where('user_id', $user->id)
                            ->whereNotNull('last_read_at')
                            ->whereColumn('last_read_at', '>=', 'messages.created_at');
                    });
            }])
            ->orderByDesc('updated_at')
            ->take(3)
            ->get();

        // Wishlist count
        $wishlistCount = $user->wishlistArtists()->notBlockedBy($user)->count();

        // Suggested artists (6)
        $suggestedArtists = $this->getSuggestedArtists($user, 6);

        return response()->json([
            'appointments' => ClientDashboardAppointmentResource::collection($appointments),
            'conversations' => ConversationResource::collection($conversations),
            'wishlist_count' => $wishlistCount,
            'suggested_artists' => $suggestedArtists,
        ]);
    }

    /**
     * Get wishlist artists for the authenticated client (intentional tracking for book openings).
     */
    public function getWishlist(Request $request): JsonResponse
    {
        $user = $request->user();

        $wishlistItems = $user->wishlistArtists()
            ->notBlockedBy($user)
            ->with(['image', 'studio', 'styles', 'settings'])
            ->withPivot('notify_booking_open', 'notified_at', 'created_at')
            ->get();

        return response()->json([
            'wishlist' => WishlistArtistResource::collection($wishlistItems),
        ]);
    }

    /**
     * Get favorited/saved artists for the authenticated client (from users_artists table).
     * These are casual saves from the bookmark button.
     */
    public function getFavorites(Request $request): JsonResponse
    {
        $user = $request->user();

        // Query users_artists table (favorites) with settings eager loaded
        $favoriteArtists = $user->artists()
            ->notBlockedBy($user)
            ->with(['image', 'studio', 'styles', 'settings'])
            ->get();

        return response()->json([
            'favorites' => DashboardArtistResource::collection($favoriteArtists),
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
        if ($user->wishlistArtists()->where('artist_id', $artistId)->exists()) {
            return response()->json(['error' => 'Artist already on wishlist'], 409);
        }

        ArtistWishlist::create([
            'user_id' => $user->id,
            'artist_id' => $artistId,
            'notify_booking_open' => $request->get('notify_booking_open', true),
        ]);

        return response()->json(['success' => true], 201);
    }

    /**
     * Remove an artist from the wishlist.
     */
    public function removeFromWishlist(Request $request, int $artistId): JsonResponse
    {
        $user = $request->user();

        $deleted = ArtistWishlist::where('user_id', $user->id)
            ->where('artist_id', $artistId)
            ->delete();

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

        $wishlistItem = ArtistWishlist::where('user_id', $user->id)
            ->where('artist_id', $artistId)
            ->first();

        if (!$wishlistItem) {
            return response()->json(['error' => 'Artist not on wishlist'], 404);
        }

        $wishlistItem->update([
            'notify_booking_open' => $request->notify_booking_open,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Get suggested artists based on user preferences.
     */
    public function getSuggestedArtistsEndpoint(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = $request->get('limit', 6);

        $artists = $this->getSuggestedArtists($user, $limit);

        return response()->json([
            'artists' => $artists,
        ]);
    }

    /**
     * Get suggested artists based on favorited styles and tattoos.
     */
    private function getSuggestedArtists(User $user, int $limit = 6): array
    {
        // Get user's favorited style IDs
        $favoritedStyleIds = $user->styles()->pluck('styles.id')->toArray();

        // Get styles from favorited tattoos
        $tattooStyleIds = $user->tattoos()
            ->with('styles')
            ->get()
            ->flatMap(fn ($tattoo) => $tattoo->styles->pluck('id'))
            ->unique()
            ->toArray();

        $allStyleIds = array_unique(array_merge($favoritedStyleIds, $tattooStyleIds));

        // Get IDs of already-followed artists and wishlist artists
        $excludeArtistIds = $user->artists()->pluck('users.id')->toArray();
        $wishlistArtistIds = $user->wishlistArtists()->notBlockedBy($user)->pluck('users.id')->toArray();
        $excludeArtistIds = array_merge($excludeArtistIds, $wishlistArtistIds);

        // Find artists with matching styles
        $query = Artist::query()
            ->with(['image', 'studio', 'styles', 'settings'])
            ->where('id', '!=', $user->id);

        if (!empty($excludeArtistIds)) {
            $query->whereNotIn('id', $excludeArtistIds);
        }

        if (!empty($allStyleIds)) {
            $query->whereHas('styles', function ($q) use ($allStyleIds) {
                $q->whereIn('styles.id', $allStyleIds);
            });
        }

        $artists = $query->inRandomOrder()
            ->take($limit)
            ->get();

        return SuggestedArtistResource::collection($artists)->resolve();
    }
}
