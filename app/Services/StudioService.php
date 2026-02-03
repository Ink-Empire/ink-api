<?php

namespace App\Services;

use App\Exceptions\StudioNotFoundException;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Image;
use App\Models\ProfileView;
use App\Models\Studio;
use App\Models\StudioAnnouncement;
use App\Models\StudioSpotlight;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 *
 */
class StudioService
{

    /**
     * Get studio by ID or slug
     * @param $id
     */
    public function getById($id): ?Studio
    {
        if (!$id) {
            return null;
        }

        // If numeric, search by ID; otherwise search by slug
        if (is_numeric($id)) {
            return Studio::where('id', $id)->first();
        }

        return Studio::where('slug', $id)->first();
    }

    /**
     *
     */
    public function get()
    {
        return Studio::paginate(25);
    }


    /**
     * @throws StudioNotFoundException
     */
    public function setStudioImage(string $studio_id, Image $image): Studio
    {
        $studio = $this->getById($studio_id);

        if ($studio) {
            $studio->image_id = $image->id;
            $studio->save();
        } else {
            throw new StudioNotFoundException();
        }

        return $studio;
    }

    public function setBusinessDays(array $data, $studio)
    {
        if (isset($data['start']) && isset($data['end'])) {
            foreach ($data['days'] as $day) {
                $studio->business_hours()->updateOrCreate(
                    [
                        'day_id' => $day,
                        'studio_id' => $studio->id
                    ],
                    [
                        'day_id' => $day,
                        'open_time' => $data['start'],
                        'close_time' => $data['end']
                    ]);
            }
        }
    }


    public function updateStyles(?Studio $studio, $stylesArray): void
    {
        $studio->styles()->sync($stylesArray);
    }

    public function updateTattoos(?Studio $studio, mixed $tattooArray): void
    {
        //
    }

    public function updateArtists(?Studio $studio, mixed $fieldVal): void
    {
        if ($studio && is_array($fieldVal)) {
            $studio->artists()->sync($fieldVal);
        }
    }

    public function addArtistByUsernameOrEmail(Studio $studio, string $identifier, string $initiatedBy = 'studio'): ?User
    {
        // Search by username or email
        $user = User::where('type_id', 2) // Artist type
            ->where(function ($query) use ($identifier) {
                $query->where('username', $identifier)
                      ->orWhere('email', $identifier);
            })
            ->first();

        if ($user) {
            // Add with is_verified = false (pending verification)
            // initiated_by tracks who initiated: 'studio' = invitation, 'artist' = request
            $studio->artists()->syncWithoutDetaching([
                $user->id => [
                    'is_verified' => false,
                    'initiated_by' => $initiatedBy,
                ]
            ]);
            return $user;
        }

        return null;
    }

    public function removeArtist(Studio $studio, int $userId): bool
    {
        return $studio->artists()->detach($userId) > 0;
    }

    public function getStudioArtists(Studio $studio)
    {
        return $studio->artists()->with(['image', 'styles'])->get();
    }

    public function createAnnouncement(Studio $studio, array $data): StudioAnnouncement
    {
        return $studio->announcements()->create([
            'title' => $data['title'],
            'content' => $data['content'],
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    public function updateAnnouncement(StudioAnnouncement $announcement, array $data): StudioAnnouncement
    {
        $announcement->update($data);
        return $announcement->fresh();
    }

    public function deleteAnnouncement(StudioAnnouncement $announcement): bool
    {
        return $announcement->delete();
    }

    public function addSpotlight(Studio $studio, string $type, int $itemId, int $order = 0): StudioSpotlight
    {
        return $studio->spotlights()->updateOrCreate(
            [
                'spotlightable_type' => $type,
                'spotlightable_id' => $itemId,
            ],
            [
                'display_order' => $order,
            ]
        );
    }

    public function removeSpotlight(StudioSpotlight $spotlight): bool
    {
        return $spotlight->delete();
    }

    public function getSpotlightsWithData(Studio $studio)
    {
        $spotlights = $studio->spotlights;

        return $spotlights->map(function ($spotlight) {
            $item = $spotlight->spotlighted_item;
            return [
                'id' => $spotlight->id,
                'type' => $spotlight->spotlightable_type,
                'item_id' => $spotlight->spotlightable_id,
                'display_order' => $spotlight->display_order,
                'item' => $item,
            ];
        });
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
}
