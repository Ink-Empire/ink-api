<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Artist;
use App\Models\ArtistWishlist;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProfileView;
use App\Models\Studio;
use App\Models\StudioAvailability;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function __construct(
        protected StudioService $studioService,
        protected TattooService $tattooService
    ) {
    }

    /**
     * Get complete dashboard data for a studio.
     * Combines: studio details, artists, announcements, stats, and working hours.
     */
    public function getStudioDashboardData(Studio $studio): array
    {
        $artists = $this->studioService->getStudioArtists($studio);
        $workingHours = StudioAvailability::where('studio_id', $studio->id)->get();
        $stats = $this->getStudioStatsData($studio);

        return [
            'studio' => $studio,
            'artists' => $artists,
            'announcements' => $studio->announcements,
            'stats' => $stats,
            'working_hours' => $workingHours,
        ];
    }

    /**
     * Get cached dashboard statistics for a studio.
     * Cache duration: 5 minutes.
     */
    public function getStudioStatsData(Studio $studio): array
    {
        $cacheKey = "studio:{$studio->id}:dashboard-stats";
        $cacheDuration = 300; // 5 minutes

        return Cache::remember($cacheKey, $cacheDuration, function () use ($studio) {
            $now = Carbon::now();
            $sevenDaysAgo = $now->copy()->subDays(7);
            $fourteenDaysAgo = $now->copy()->subDays(14);

            // Page views - this week vs last week
            $viewsThisWeek = ProfileView::where('viewable_type', Studio::class)
                ->where('viewable_id', $studio->id)
                ->where('created_at', '>=', $sevenDaysAgo)
                ->count();

            $viewsLastWeek = ProfileView::where('viewable_type', Studio::class)
                ->where('viewable_id', $studio->id)
                ->whereBetween('created_at', [$fourteenDaysAgo, $sevenDaysAgo])
                ->count();

            $viewsTrend = $viewsLastWeek > 0
                ? round((($viewsThisWeek - $viewsLastWeek) / $viewsLastWeek) * 100)
                : ($viewsThisWeek > 0 ? 100 : 0);

            // Studio artists
            $artistIds = $studio->artists()->pluck('users.id')->toArray();

            // Get bookings for studio artists this week
            $bookingsThisWeek = Appointment::whereIn('artist_id', $artistIds)
                ->where('created_at', '>=', $sevenDaysAgo)
                ->count();

            $bookingsLastWeek = Appointment::whereIn('artist_id', $artistIds)
                ->whereBetween('created_at', [$fourteenDaysAgo, $sevenDaysAgo])
                ->count();

            $bookingsTrend = $bookingsThisWeek - $bookingsLastWeek;

            // Studio inquiries
            $inquiriesThisWeek = Conversation::whereHas('participants', function ($q) use ($artistIds) {
                    $q->whereIn('user_id', $artistIds);
                })
                ->where('created_at', '>=', $sevenDaysAgo)
                ->count();

            $inquiriesLastWeek = Conversation::whereHas('participants', function ($q) use ($artistIds) {
                    $q->whereIn('user_id', $artistIds);
                })
                ->whereBetween('created_at', [$fourteenDaysAgo, $sevenDaysAgo])
                ->count();

            $inquiriesTrend = $inquiriesThisWeek - $inquiriesLastWeek;

            return [
                'page_views' => [
                    'count' => $viewsThisWeek,
                    'trend' => $viewsTrend,
                    'trend_label' => $viewsTrend >= 0 ? "+{$viewsTrend}%" : "{$viewsTrend}%",
                ],
                'bookings' => [
                    'count' => $bookingsThisWeek,
                    'trend' => $bookingsTrend,
                    'trend_label' => $bookingsTrend >= 0 ? "+{$bookingsTrend}" : "{$bookingsTrend}",
                ],
                'inquiries' => [
                    'count' => $inquiriesThisWeek,
                    'trend' => $inquiriesTrend,
                    'trend_label' => $inquiriesTrend > 0 ? 'New' : '',
                ],
                'artists_count' => count($artistIds),
            ];
        });
    }

    /**
     * Get complete dashboard data for an artist.
     */
    public function getArtistDashboardData(User $artist): array
    {
        $stats = $this->getArtistStatsData($artist);
        $schedule = $this->getArtistUpcomingSchedule($artist);
        $tattoos = $this->getCachedArtistTattoos($artist);

        return [
            'artist' => $artist,
            'stats' => $stats,
            'schedule' => $schedule,
            'tattoos' => $tattoos,
        ];
    }

    /**
     * Get cached tattoos for an artist.
     * Cache duration: 5 minutes (tattoos don't change often).
     */
    public function getCachedArtistTattoos(User $artist, int $limit = 12): array
    {
        $cacheKey = "artist:{$artist->id}:dashboard-tattoos";
        $cacheDuration = 300; // 5 minutes

        return Cache::remember($cacheKey, $cacheDuration, function () use ($artist, $limit) {
            $result = $this->tattooService->getByArtistId($artist->id);
            $tattoos = $result['response'] ?? [];

            if ($tattoos instanceof \Illuminate\Support\Collection) {
                $tattoos = $tattoos->values()->toArray();
            }

            return array_slice($tattoos, 0, $limit);
        });
    }

    /**
     * Get cached dashboard statistics for an artist.
     * Cache duration: 3 minutes.
     */
    public function getArtistStatsData(User $artist): array
    {
        $cacheKey = "artist:{$artist->id}:dashboard-stats";
        $cacheDuration = 180; // 3 minutes

        return Cache::remember($cacheKey, $cacheDuration, function () use ($artist) {
            $now = Carbon::now();
            $sevenDaysAgo = $now->copy()->subDays(7);
            $fourteenDaysAgo = $now->copy()->subDays(14);

            // Profile views - this week vs last week
            $viewsThisWeek = ProfileView::where('viewable_type', User::class)
                ->where('viewable_id', $artist->id)
                ->where('created_at', '>=', $sevenDaysAgo)
                ->count();

            $viewsLastWeek = ProfileView::where('viewable_type', User::class)
                ->where('viewable_id', $artist->id)
                ->whereBetween('created_at', [$fourteenDaysAgo, $sevenDaysAgo])
                ->count();

            $viewsTrend = $viewsLastWeek > 0
                ? round((($viewsThisWeek - $viewsLastWeek) / $viewsLastWeek) * 100)
                : ($viewsThisWeek > 0 ? 100 : 0);

            // Saves count - users who saved this artist
            $savesCount = DB::table('users_artists')
                ->where('artist_id', $artist->id)
                ->count();

            // Saves this week vs last week
            $savesThisWeek = DB::table('users_artists')
                ->where('artist_id', $artist->id)
                ->where('created_at', '>=', $sevenDaysAgo)
                ->count();

            $savesLastWeek = DB::table('users_artists')
                ->where('artist_id', $artist->id)
                ->whereBetween('created_at', [$fourteenDaysAgo, $sevenDaysAgo])
                ->count();

            $savesTrend = $savesThisWeek - $savesLastWeek;

            // Upcoming appointments
            $upcomingAppointments = Appointment::where('artist_id', $artist->id)
                ->where('status', 'booked')
                ->where('date', '>=', $now->toDateString())
                ->count();

            // Appointments trend (this week scheduled vs last week)
            $appointmentsThisWeek = Appointment::where('artist_id', $artist->id)
                ->where('status', 'booked')
                ->whereBetween('date', [$now->toDateString(), $now->copy()->addDays(7)->toDateString()])
                ->count();

            $appointmentsLastWeek = Appointment::where('artist_id', $artist->id)
                ->where('status', 'booked')
                ->whereBetween('date', [$sevenDaysAgo->toDateString(), $now->toDateString()])
                ->count();

            $appointmentsTrend = $appointmentsThisWeek - $appointmentsLastWeek;

            // Unread messages count - NOT cached (always fresh)
            $unreadMessages = Message::where('recipient_id', $artist->id)
                ->whereNull('read_at')
                ->count();

            $profileViewsTotal = ProfileView::where('viewable_type', User::class)
                ->where('viewable_id', $artist->id)
                ->count();

            return [
                'profile_views' => $viewsThisWeek,
                'profile_views_total' => $profileViewsTotal,
                'views_trend' => ($viewsTrend >= 0 ? '+' : '') . $viewsTrend . '%',
                'saves_count' => $savesCount,
                'saves_this_week' => $savesThisWeek,
                'saves_trend' => ($savesTrend >= 0 ? '+' : '') . $savesTrend,
                'upcoming_appointments' => $upcomingAppointments,
                'appointments_trend' => ($appointmentsTrend >= 0 ? '+' : '') . $appointmentsTrend,
                'unread_messages' => $unreadMessages,
            ];
        });
    }

    /**
     * Get upcoming schedule for an artist.
     */
    public function getArtistUpcomingSchedule(User $artist, int $limit = 10): array
    {
        $cacheKey = "artist:{$artist->id}:upcoming-schedule";
        $cacheDuration = 300; // 5 minutes

        return Cache::remember($cacheKey, $cacheDuration, function () use ($artist, $limit) {
            return $this->fetchArtistUpcomingSchedule($artist, $limit);
        });
    }

    private function fetchArtistUpcomingSchedule(User $artist, int $limit): array
    {
        $appointments = Appointment::where('artist_id', $artist->id)
            ->where('status', 'booked')
            ->where('date', '>=', Carbon::now()->toDateString())
            ->orderBy('date')
            ->orderBy('start_time')
            ->limit($limit)
            ->with('client')
            ->get();

        return $appointments->map(function ($apt) {
            $date = Carbon::parse($apt->date);
            $startTime = Carbon::parse($apt->start_time)->format('g:i A');
            $endTime = Carbon::parse($apt->end_time)->format('g:i A');

            $clientName = $apt->client?->name ?? 'Unknown Client';
            $nameParts = explode(' ', $clientName);
            $initials = count($nameParts) >= 2
                ? strtoupper($nameParts[0][0] . $nameParts[1][0])
                : strtoupper(substr($clientName, 0, 2));

            return [
                'id' => $apt->id,
                'date' => $date->format('Y-m-d'),
                'day' => $date->day,
                'month' => $date->format('M'),
                'time' => "{$startTime} – {$endTime}",
                'title' => $apt->title ?? 'Appointment',
                'clientName' => $clientName,
                'clientInitials' => $initials,
                'type' => $apt->type ?? 'appointment',
            ];
        })->toArray();
    }

    // ==================== Client Dashboard Methods ====================

    /**
     * Get aggregated dashboard data for a client.
     * Includes favorites to avoid separate API call.
     * Caches suggested artists for 2 minutes (appointments/conversations always fresh).
     */
    public function getClientDashboardData(User $user): array
    {
        // Upcoming appointments (next 5, sorted by date) - always fresh
        $appointments = $user->appointmentsWithStatus('booked')
            ->where('date', '>=', now()->toDateString())
            ->with(['artist.image', 'studio'])
            ->orderBy('date')
            ->orderBy('start_time')
            ->take(5)
            ->get();

        // Recent conversations (last 3) - always fresh
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

        // Favorites/wishlist - include in main response to avoid separate call
        $favorites = $user->artists()
            ->notBlockedBy($user)
            ->with(['image', 'studio', 'styles', 'settings'])
            ->get();

        // Wishlist count
        $wishlistCount = $favorites->count();

        // Suggested artists - cached for 2 minutes (expensive query)
        $suggestedArtists = $this->getCachedSuggestedArtists($user, 6);

        return [
            'appointments' => $appointments,
            'conversations' => $conversations,
            'favorites' => $favorites,
            'wishlist_count' => $wishlistCount,
            'suggested_artists' => $suggestedArtists,
        ];
    }

    /**
     * Get cached suggested artists for a user.
     * Cache duration: 2 minutes.
     */
    private function getCachedSuggestedArtists(User $user, int $limit = 6): \Illuminate\Database\Eloquent\Collection
    {
        $cacheKey = "client:{$user->id}:suggested-artists";
        $cacheDuration = 120; // 2 minutes

        return Cache::remember($cacheKey, $cacheDuration, function () use ($user, $limit) {
            return $this->getSuggestedArtists($user, $limit);
        });
    }

    /**
     * Get wishlist artists for a client.
     */
    public function getClientWishlist(User $user)
    {
        return $user->wishlistArtists()
            ->notBlockedBy($user)
            ->with(['image', 'studio', 'styles', 'settings'])
            ->withPivot('notify_booking_open', 'notified_at', 'created_at')
            ->get();
    }

    /**
     * Get favorited/saved artists for a client.
     */
    public function getClientFavorites(User $user)
    {
        return $user->artists()
            ->notBlockedBy($user)
            ->with(['image', 'studio', 'styles', 'settings'])
            ->get();
    }

    /**
     * Get saved tattoos for a client.
     */
    public function getClientSavedTattoos(User $user): array
    {
        $tattooIds = $user->tattoos()->pluck('tattoos.id')->toArray();

        if (empty($tattooIds)) {
            return [];
        }

        // Get full tattoo data from Elasticsearch
        $result = $this->tattooService->getByIds($tattooIds);
        $tattoos = $result['response'] ?? [];

        // Handle Collection vs array
        if ($tattoos instanceof \Illuminate\Support\Collection) {
            $tattoos = $tattoos->values()->toArray();
        }

        return $tattoos;
    }

    /**
     * Get suggested artists based on user preferences.
     */
    public function getSuggestedArtists(User $user, int $limit = 6): \Illuminate\Database\Eloquent\Collection
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

        return $query->inRandomOrder()
            ->take($limit)
            ->get();
    }

    /**
     * Add an artist to the user's wishlist.
     */
    public function addToWishlist(User $user, int $artistId, bool $notifyBookingOpen = true): ArtistWishlist
    {
        return ArtistWishlist::create([
            'user_id' => $user->id,
            'artist_id' => $artistId,
            'notify_booking_open' => $notifyBookingOpen,
        ]);
    }

    /**
     * Remove an artist from the user's wishlist.
     */
    public function removeFromWishlist(User $user, int $artistId): bool
    {
        return ArtistWishlist::where('user_id', $user->id)
            ->where('artist_id', $artistId)
            ->delete() > 0;
    }

    /**
     * Update wishlist notification settings.
     */
    public function updateWishlistItem(User $user, int $artistId, bool $notifyBookingOpen): bool
    {
        $wishlistItem = ArtistWishlist::where('user_id', $user->id)
            ->where('artist_id', $artistId)
            ->first();

        if (!$wishlistItem) {
            return false;
        }

        return $wishlistItem->update([
            'notify_booking_open' => $notifyBookingOpen,
        ]);
    }

    /**
     * Check if an artist is on the user's wishlist.
     */
    public function isOnWishlist(User $user, int $artistId): bool
    {
        return $user->wishlistArtists()->where('artist_id', $artistId)->exists();
    }
}
