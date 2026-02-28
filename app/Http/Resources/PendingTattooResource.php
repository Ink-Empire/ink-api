<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PendingTattooResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'primary_image' => $this->primary_image ? [
                'id' => $this->primary_image->id,
                'uri' => $this->primary_image->uri,
            ] : null,
            'images' => $this->images->map(fn ($img) => [
                'id' => $img->id,
                'uri' => $img->uri,
            ]),
            'styles' => $this->styles->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
            ]),
            'approval_status' => $this->approval_status,
            'uploader' => $this->uploader ? [
                'id' => $this->uploader->id,
                'name' => $this->uploader->name,
                'slug' => $this->uploader->slug,
                'image' => $this->uploader->image ? [
                    'id' => $this->uploader->image->id,
                    'uri' => $this->uploader->image->uri,
                ] : null,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
