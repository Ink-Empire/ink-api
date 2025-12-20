<?php

namespace App\Http\Controllers;

use App\Enums\UserTypes;
use App\Http\Resources\ConversationResource;
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
            ->orderBy('date')
            ->orderBy('start_time')
            ->take(5)
            ->get()
            ->map(fn ($apt) => $this->formatAppointment($apt));

        // Recent conversations (last 3)
        $conversations = Conversation::forUser($user->id)
            ->with(['users', 'latestMessage.sender'])
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
        $wishlistCount = $user->wishlistArtists()->count();

        // Suggested artists (6)
        $suggestedArtists = $this->getSuggestedArtists($user, 6);

        return response()->json([
            'appointments' => $appointments,
            'conversations' => ConversationResource::collection($conversations),
            'wishlist_count' => $wishlistCount,
            'suggested_artists' => $suggestedArtists,
        ]);
    }

    /**
     * Get wishlist artists for the authenticated client.
     */
    public function getWishlist(Request $request): JsonResponse
    {
        $user = $request->user();

        $wishlistItems = $user->wishlistArtists()
            ->with(['image', 'studio', 'styles'])
            ->withPivot('notify_booking_open', 'notified_at', 'created_at')
            ->get()
            ->map(fn ($artist) => $this->formatWishlistArtist($artist));

        return response()->json([
            'wishlist' => $wishlistItems,
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
        $wishlistArtistIds = $user->wishlistArtists()->pluck('users.id')->toArray();
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
            ->get()
            ->map(fn ($artist) => $this->formatSuggestedArtist($artist));

        return $artists->toArray();
    }

    /**
     * Format an appointment for the dashboard.
     */
    private function formatAppointment($appointment): array
    {
        return [
            'id' => $appointment->id,
            'title' => $appointment->title,
            'date' => $appointment->date,
            'start_time' => $appointment->start_time,
            'end_time' => $appointment->end_time,
            'status' => $appointment->status,
            'type' => $appointment->type,
            'description' => $appointment->description,
            'artist' => [
                'id' => $appointment->artist->id,
                'name' => $appointment->artist->name,
                'username' => $appointment->artist->username,
                'image' => $appointment->artist->image ? [
                    'id' => $appointment->artist->image->id,
                    'uri' => $appointment->artist->image->uri,
                ] : null,
            ],
            'studio' => $appointment->studio ? [
                'id' => $appointment->studio->id,
                'name' => $appointment->studio->name,
            ] : null,
        ];
    }

    /**
     * Format an artist for the wishlist.
     */
    private function formatWishlistArtist($artist): array
    {
        $settings = $artist->settings ?? null;

        return [
            'id' => $artist->id,
            'name' => $artist->name,
            'username' => $artist->username,
            'image' => $artist->image ? [
                'id' => $artist->image->id,
                'uri' => $artist->image->uri,
            ] : null,
            'studio' => $artist->studio ? [
                'id' => $artist->studio->id,
                'name' => $artist->studio->name,
            ] : null,
            'styles' => $artist->styles->map(fn ($style) => [
                'id' => $style->id,
                'name' => $style->name,
            ])->take(3)->values()->toArray(),
            'books_open' => $settings?->books_open ?? false,
            'notify_booking_open' => $artist->pivot->notify_booking_open,
            'notified_at' => $artist->pivot->notified_at,
            'added_at' => $artist->pivot->created_at,
        ];
    }

    /**
     * Format a suggested artist.
     */
    private function formatSuggestedArtist($artist): array
    {
        $settings = $artist->settings ?? null;

        return [
            'id' => $artist->id,
            'name' => $artist->name,
            'username' => $artist->username,
            'image' => $artist->image ? [
                'id' => $artist->image->id,
                'uri' => $artist->image->uri,
            ] : null,
            'studio' => $artist->studio ? [
                'id' => $artist->studio->id,
                'name' => $artist->studio->name,
            ] : null,
            'styles' => $artist->styles->map(fn ($style) => [
                'id' => $style->id,
                'name' => $style->name,
            ])->take(3)->values()->toArray(),
            'books_open' => $settings?->books_open ?? false,
        ];
    }
}
