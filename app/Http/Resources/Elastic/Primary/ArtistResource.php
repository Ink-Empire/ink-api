<?php

namespace App\Http\Resources\Elastic\Primary;

use App\Http\Resources\StudioResource;
use App\Http\Resources\StyleResource;
use Illuminate\Http\Resources\Json\JsonResource;
use App\Http\Resources\Elastic\TattooResource as TattooResource;

class ArtistResource extends JsonResource
{
//    protected $user_id;
//
//    public function user_id($value): static
//    {
//        if ($value) {
//            $this->user_id = $value;
//            return $this;
//        }
//    }

    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'about' => $this->about,
            'email' => $this->email,
            'location' => $this->location,
            'location_lat_long' => $this->location_lat_long,
            'name' => $this->name,
            'phone' => $this->phone,
            'studio' => new StudioResource($this->studio),
            'studio_name' => $this->studio?->name,
            'type' => $this->type->name,
            'styles' => StyleResource::collection($this->styles),
            'tattoos' => TattooResource::collection($this->tattoos),
            'primary_image' => $this->primary_image ?? null,
        ];
    }
}
