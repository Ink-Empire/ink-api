<?php

namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class SelfUserResource extends JsonResource
{
    public function toArray($request)
    {
        // Get all verified studios with pivot data
        $verifiedStudios = $this->verifiedStudios()->with('image')->get();
        $studiosData = $verifiedStudios->map(function ($studio) {
            return [
                'id' => $studio->id,
                'name' => $studio->name,
                'slug' => $studio->slug,
                'image' => $studio->image ? [
                    'id' => $studio->image->id,
                    'uri' => $studio->image->uri,
                ] : null,
                'is_primary' => (bool) $studio->pivot->is_primary,
            ];
        })->values()->toArray();

        // Get primary studio for backwards compatibility
        $primaryStudio = $verifiedStudios->firstWhere('pivot.is_primary', true)
            ?? $verifiedStudios->first();
        $primaryStudioData = $primaryStudio ? [
            'id' => $primaryStudio->id,
            'name' => $primaryStudio->name,
            'slug' => $primaryStudio->slug,
            'image' => $primaryStudio->image ? [
                'id' => $primaryStudio->image->id,
                'uri' => $primaryStudio->image->uri,
            ] : null,
        ] : null;

        return [
            'id' => $this->id,
            'about' => $this->about,
            'email' => $this->email,
            'image' => $this->image->uri ?? "",
            'location' => $this->location,
            'location_lat_long' => $this->location_lat_long,
            'name' => $this->name,
            'phone' => $this->phone,
            'slug' => $this->slug,
            'studio' => $primaryStudioData, // Primary studio for backwards compatibility
            'studios_affiliated' => $studiosData, // All verified studios
            'studio_name' => $primaryStudio?->name ?? $this->studio_name ?? "",
            'type' => $this->type->name,
            'type_id' => $this->type_id,
            'styles' => $this->styles->pluck('id')->toArray(),
            'favorites' => [
                'artists' => $this->artists->pluck('id')->toArray(),
                'tattoos' => $this->tattoos->pluck('id')->toArray(),
                'studios' => [],
            ],
            'username' => $this->username,
            // Admin fields
            'is_admin' => (bool) $this->is_admin,
            // Studio admin fields
            'is_studio_admin' => $this->ownedStudio !== null,
            'owned_studio' => $this->ownedStudio ? [
                'id' => $this->ownedStudio->id,
                'name' => $this->ownedStudio->name,
                'slug' => $this->ownedStudio->slug,
            ] : null,
            // Blocked users - IDs of users this user has blocked
            'blocked_user_ids' => $this->blockedUsers->pluck('id')->toArray(),
            // Blocked users with full details for management UI
            'blocked_users' => $this->blockedUsers->map(function ($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'slug' => $user->slug,
                    'image' => $user->image ? $user->image->uri : null,
                ];
            })->values()->toArray(),
            // Social media links
            'social_media_links' => $this->socialMediaLinks->map(function ($link) {
                return [
                    'platform' => $link->platform,
                    'username' => $link->username,
                    'url' => $link->url,
                ];
            })->values()->toArray(),
        ];
    }
}
