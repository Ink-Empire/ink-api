<?php

namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class SelfUserResource extends JsonResource
{
    public function toArray($request)
    {
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
            'studio' => $this->studio,
            'studio_name' => $this->studio_name ?? "",
            'type' => $this->type->name,
            'type_id' => $this->type_id,
            'artists' => $this->artists->pluck('id')->toArray(),
            'styles' => $this->styles->pluck('id')->toArray(),
            'studios' => $this->studios,
            'tattoos' => $this->tattoos->pluck('id')->toArray(),
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
        ];
    }
}
