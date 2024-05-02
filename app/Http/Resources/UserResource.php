<?php

namespace App\Http\Resources;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'studio' => $this->studio,
            'studio_name' => $this->studio_name ?? "",
            'type' => $this->type->name,
            'artists' => $this->artists,
            'styles' => $this->styles->pluck('id')->toArray(),
            'studios' => $this->studios
        ];
    }
}
