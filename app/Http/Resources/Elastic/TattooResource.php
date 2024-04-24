<?php

namespace App\Http\Resources\Elastic;

use App\Http\Resources\Elastic\ArtistResource;
use App\Http\Resources\StudioResource;
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
            'studio' => $this->studio->name ?? "",
            'primary_style' => $this->primary_style->name ?? "",
            'primary_subject' => $this->subject->name ?? "",
            'primary_image' => $this->primary_image ?? null,
            'images' => $this->images,
            'styles' => $this->styles,
        ];
    }
}
