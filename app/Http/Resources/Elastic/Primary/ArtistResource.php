<?php

namespace App\Http\Resources\Elastic\Primary;

use App\Http\Resources\StudioResource;
use App\Http\Resources\StyleResource;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Elastic\TattooResource as TattooResource;

class ArtistResource extends JsonResource
{
    protected $user_id;

    public function user_id($value): static
    {
        $this->user_id = $value;
        return $this;
    }

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
            'studio' => new StudioResource($this->studio),
            'type' => $this->type->name,
            'styles' => StyleResource::collection($this->styles),
            'tattoos' => TattooResource::collection($this->tattoos)
        ];
    }
}
