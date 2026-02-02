<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource for artist cards displayed on dashboards.
 * Used for suggested artists, favorites, and wishlist items.
 */
class DashboardArtistResource extends JsonResource
{
    /**
     * Maximum number of styles to include.
     */
    protected int $styleLimit = 3;

    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'username' => $this->username,
            'image' => $this->when(
                $this->relationLoaded('image') && $this->image,
                fn () => new BriefImageResource($this->image)
            ),
            'studio' => $this->when(
                $this->relationLoaded('studio') && $this->studio->isNotEmpty(),
                fn () => new BriefStudioResource($this->studio->first())
            ),
            'styles' => $this->when(
                $this->relationLoaded('styles'),
                fn () => StyleResource::collection($this->styles->take($this->styleLimit))
            ),
            'books_open' => $this->when(
                $this->relationLoaded('settings'),
                fn () => (bool) ($this->settings?->books_open ?? false),
                false
            ),
        ];
    }
}
