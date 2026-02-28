<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SelfUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Expects the following relationships to be pre-loaded:
     * - verifiedStudios.image
     * - ownedStudio
     * - blockedUsers.image
     * - styles
     * - socialMediaLinks
     * - artists (for favorites)
     * - tattoos (for favorites)
     * - studios (for favorites)
     * - type
     * - image
     */
    public function toArray($request)
    {
        // Get primary studio from pre-loaded verifiedStudios for backwards compatibility
        $primaryStudio = $this->verifiedStudios->firstWhere('pivot.is_primary', true)
            ?? $this->verifiedStudios->first();

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
            // Primary studio for backwards compatibility
            'studio' => $primaryStudio ? [
                'id' => $primaryStudio->id,
                'name' => $primaryStudio->name,
                'slug' => $primaryStudio->slug,
                'image' => $primaryStudio->image ? new BriefImageResource($primaryStudio->image) : null,
            ] : null,
            // All verified studios
            'studios_affiliated' => AffiliatedStudioResource::collection($this->verifiedStudios),
            'studio_name' => $primaryStudio?->name ?? $this->studio_name ?? "",
            'type' => $this->type->name,
            'type_id' => $this->type_id,
            'styles' => $this->styles->pluck('id')->toArray(),
            'favorites' => [
                'artists' => $this->artists->pluck('id')->toArray(),
                'tattoos' => $this->tattoos->pluck('id')->toArray(),
                'studios' => $this->studios->pluck('id')->toArray(),
            ],
            'username' => $this->username,
            // Admin fields
            'is_admin' => (bool) $this->is_admin,
            // Owned studio (if user owns a studio)
            'owned_studio' => $this->ownedStudio ? new BriefStudioResource($this->ownedStudio) : null,
            // Blocked users - IDs of users this user has blocked
            'blocked_user_ids' => $this->blockedUsers->pluck('id')->toArray(),
            // Blocked users with full details for management UI
            'blocked_users' => BlockedUserResource::collection($this->blockedUsers),
            // Social media links
            'social_media_links' => SocialMediaLinkResource::collection($this->socialMediaLinks),
            // Email preferences
            'email_unsubscribed' => (bool) $this->email_unsubscribed,
            'is_email_verified' => $this->is_email_verified,
            'email_verified_at' => $this->email_verified_at
        ];
    }
}
