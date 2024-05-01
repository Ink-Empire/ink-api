<?php

namespace App\Http\Resources\Elastic\Primary;

use App\Http\Resources\Elastic\ArtistResource;
use App\Http\Resources\StudioResource;
use App\Http\Resources\StyleResource;
use Illuminate\Http\Resources\Json\JsonResource;

class TattooResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'placement' => $this->placement,
            'artist' => new ArtistResource($this->artist),
            'studio' => new StudioResource($this->studio),
            'primary_style' => $this->primary_style->name ?? "",
            'primary_subject' => $this->subject->name ?? "",
            'primary_image' => $this->primary_image ?? null,
            'images' => $this->images,
            'styles' => StyleResource::collection($this->styles),
            'tags' => $this->tags->pluck('tag')->toArray(),
        ];
    }
}
