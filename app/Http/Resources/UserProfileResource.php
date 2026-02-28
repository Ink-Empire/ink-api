<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class UserProfileResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'username' => $this->username,
            'about' => $this->about,
            'location' => $this->location,
            'image' => $this->image ? [
                'id' => $this->image->id,
                'uri' => $this->image->uri,
            ] : null,
            'uploaded_tattoo_count' => $this->uploaded_tattoos_count ?? 0,
            'social_media_links' => $this->socialMediaLinks->map(function ($link) {
                return [
                    'platform' => $link->platform,
                    'username' => $link->username,
                    'url' => $link->url,
                ];
            })->values()->toArray(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
