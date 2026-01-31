<?php

namespace App\Http\Resources;

/**
 * Resource for suggested artists on the client dashboard.
 * Extends DashboardArtistResource with demo indicator.
 */
class SuggestedArtistResource extends DashboardArtistResource
{
    public function toArray($request): array
    {
        return array_merge(parent::toArray($request), [
            'is_demo' => (bool) ($this->is_demo ?? false),
        ]);
    }
}
