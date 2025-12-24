<?php

namespace App\Http\Resources\Elastic;

use App\Http\Resources\StudioResource;
use App\Http\Resources\StyleResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource for indexing Artists into Elasticsearch.
 *
 * This resource is used by the Artist model's toSearchableArray() method
 * to define the document structure stored in the Elasticsearch index.
 *
 * Note: Uses TattooResource (not TattooIndexResource) for nested tattoos
 * to avoid circular references.
 *
 * @see \App\Models\Artist::toSearchableArray()
 * @see \App\Http\Resources\Elastic\TattooResource
 */
class ArtistIndexResource extends JsonResource
{
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
            'slug' => $this->slug,
            'studio' => new StudioResource($this->studio),
            'studio_name' => $this->studio?->name,
            'is_featured' => (bool) $this->is_featured,
            'styles' => StyleResource::collection($this->styles),
            'tattoos' => TattooResource::collection($this->tattoos),
            'primary_image' => $this->primary_image ?? null,
            'username' => $this->username,
            'settings' => $this->settings ?? [],
        ];
    }
}
