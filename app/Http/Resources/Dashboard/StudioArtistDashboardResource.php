<?php

namespace App\Http\Resources\Dashboard;

use App\Http\Resources\UserResource;
use Illuminate\Http\Resources\Json\JsonResource;

class StudioArtistDashboardResource extends JsonResource
{
    public function toArray($request): array
    {
        $artistData = (new UserResource($this->resource))->toArray($request);

        return array_merge($artistData, [
            'is_verified' => (bool) ($this->pivot->is_verified ?? false),
            'verified_at' => $this->pivot->verified_at ?? null,
            'initiated_by' => $this->pivot->initiated_by ?? 'artist',
        ]);
    }
}
