<?php

namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray($request)
    {
        $user = $request->user();
        $canViewPrivate = $this->canViewPrivateDetails($user);

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

        $data = [
            'id' => $this->id,
            'about' => $this->about,
            'image' => $this->image,
            'location' => $this->location,
            'name' => $this->name,
            'studio' => $primaryStudioData, // Primary studio for backwards compatibility
            'studios_affiliated' => $studiosData, // All verified studios
            'studio_name' => $primaryStudio?->name ?? $this->studio_name ?? "",
            'slug' => $this->slug,
            'type' => $this->type->name,
            'is_featured' => $this->is_featured,
            'artists' => $this->artists->pluck('id')->toArray(),
            'styles' => $this->styles->pluck('id')->toArray(),
            'studios' => $this->studios,
            'tattoos' => $this->tattoos->pluck('id')->toArray(),
            'username' => $this->username,
            'social_media_links' => $this->socialMediaLinks->map(function ($link) {
                return [
                    'platform' => $link->platform,
                    'username' => $link->username,
                    'url' => $link->url,
                ];
            })->values()->toArray(),
        ];

        // Only include sensitive fields for authorized users
        if ($canViewPrivate) {
            $data['email'] = $this->email;
            $data['phone'] = $this->phone;
            $data['location_lat_long'] = $this->location_lat_long;
            $data['is_admin'] = (bool) $this->is_admin;
            $data['owned_studio_id'] = $this->ownedStudio?->id;
            $data['is_email_verified'] = (bool) $this->is_email_verified;
            $data['email_verified_at'] = $this->email_verified_at;
        }

        return $data;
    }

    /**
     * Check if the current user can view private details.
     * Private details are visible to the user themselves or admins.
     */
    private function canViewPrivateDetails($user): bool
    {
        if (!$user) {
            return false;
        }

        // User viewing their own profile
        if ($user->id === $this->id) {
            return true;
        }

        // Admin users
        if ($user->is_admin) {
            return true;
        }

        return false;
    }
}
