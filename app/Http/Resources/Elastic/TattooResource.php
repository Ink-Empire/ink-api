<?php

namespace App\Http\Resources\Elastic;

use App\Http\Resources\StudioResource;
use Illuminate\Http\Resources\Json\JsonResource;

class TattooResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->about,
            'description' => $this->email,
            'placement' => $this->image,
            'artist' => new ArtistResource($this->artist),
            'studio' => new StudioResource($this->studio),
            'primary_style' => $this->style->name ?? "",
            'primary_subject_id' => $this->subject->name ?? "",
            'primary_image' => $this->primary_image ?? null,
            'images' => $this->images,
        ];
    }
}
