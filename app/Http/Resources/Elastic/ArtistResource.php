<?php

namespace App\Http\Resources\Elastic;

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
            'password' => $this->password,
            'phone' => $this->phone,
            'slug' => $this->slug,
            'studio' => $this->studio->name ?? "",
            'type' => $this->type->name,
            'styles' => $this->styles,
            'isFavorite' => $this->getIsUserFavorite(),
            'username' => $this->username,
        ];
    }


    private function getIsUserFavorite(): bool
    {
        if (session('user_id')) {
            if (in_array(session('user_id'), $this->users->pluck('id')->toArray())) {
                return true;
            }
        }
        return false;
    }
}
