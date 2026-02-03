<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class AffiliatedStudioResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'image' => $this->image ? new BriefImageResource($this->image) : null,
            'is_primary' => (bool) ($this->pivot?->is_primary ?? false),
        ];
    }
}
