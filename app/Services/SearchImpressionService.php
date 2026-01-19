<?php

namespace App\Services;

use App\Models\SearchImpression;
use App\Models\Studio;
use Illuminate\Support\Facades\Auth;

class SearchImpressionService
{
    /**
     * Record impressions for multiple studios.
     */
    public function recordStudioImpressions(
        array $studioIds,
        ?string $searchLocation = null,
        ?string $searchCoords = null,
        ?array $searchFilters = null,
        ?string $ipAddress = null
    ): void {
        $userId = Auth::id();

        $impressions = [];
        $now = now();

        foreach ($studioIds as $studioId) {
            $impressions[] = [
                'impressionable_type' => Studio::class,
                'impressionable_id' => $studioId,
                'user_id' => $userId,
                'search_location' => $searchLocation,
                'search_coords' => $searchCoords,
                'search_filters' => $searchFilters ? json_encode($searchFilters) : null,
                'ip_address' => $ipAddress,
                'created_at' => $now,
            ];
        }

        if (!empty($impressions)) {
            SearchImpression::insert($impressions);
        }
    }

    /**
     * Get impression count for a studio within a time period.
     */
    public function getStudioImpressionCount(int $studioId, ?string $since = null): int
    {
        $query = SearchImpression::where('impressionable_type', Studio::class)
            ->where('impressionable_id', $studioId);

        if ($since) {
            $query->where('created_at', '>=', $since);
        }

        return $query->count();
    }

    /**
     * Get weekly impression count for a studio.
     */
    public function getWeeklyImpressionCount(int $studioId): int
    {
        return $this->getStudioImpressionCount($studioId, now()->subWeek()->toDateTimeString());
    }
}
