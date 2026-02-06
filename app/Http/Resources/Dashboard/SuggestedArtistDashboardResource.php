<?php

namespace App\Http\Resources\Dashboard;

/**
 * Resource for suggested artists on the client dashboard.
 * Extends ArtistDashboardResource with demo indicator.
 */
class SuggestedArtistDashboardResource extends ArtistDashboardResource
{
    public function toArray($request): array
    {
        return array_merge(parent::toArray($request), [
            'is_demo' => (bool) ($this->is_demo ?? false),
        ]);
    }
}
