<?php

namespace App\Http\Resources\Elastic;

use App\Http\Resources\AppointmentResource;
use App\Http\Resources\WorkingHoursResource;
use Illuminate\Http\Resources\Json\JsonResource;

class ArtistResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'about' => $this->about,
            'email' => $this->email,
            'image' => $this->image,
            'location' => $this->location,
            'location_lat_long' => $this->location_lat_long,
            'name' => $this->name,
            'phone' => $this->phone,
            'slug' => $this->slug,
            'studio' => $this->studio->name ?? "",
            'type' => $this->type->name ?? null,
            'is_featured' => (int) $this->is_featured,
            'styles' => $this->whenLoaded('styles'),
            'isFavorite' => $this->getIsUserFavorite(),
            'username' => $this->username,
            'working_hours' => $this->whenLoaded('working_hours', fn() => WorkingHoursResource::collection($this->working_hours)),
            'appointments' => $this->whenLoaded('appointments', fn() => AppointmentResource::collection($this->appointments)),
            'settings' => $this->settings ?? [],
        ];
    }


    private function getIsUserFavorite(): bool
    {
        if (session('user_id') && $this->users) {
            if (in_array(session('user_id'), $this->users->pluck('id')->toArray())) {
                return true;
            }
        }
        return false;
    }
}
