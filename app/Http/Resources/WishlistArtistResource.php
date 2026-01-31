<?php

namespace App\Http\Resources;

/**
 * Resource for artists on a user's wishlist.
 * Extends DashboardArtistResource with pivot data for notification preferences.
 */
class WishlistArtistResource extends DashboardArtistResource
{
    public function toArray($request): array
    {
        return array_merge(parent::toArray($request), [
            'notify_booking_open' => (bool) ($this->pivot?->notify_booking_open ?? false),
            'notified_at' => $this->pivot?->notified_at,
            'added_at' => $this->pivot?->created_at,
        ]);
    }
}
