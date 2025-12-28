<?php

namespace App\Http\Resources\Elastic;

use App\Http\Resources\StudioResource;
use App\Http\Resources\StyleResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource for indexing Tattoos into Elasticsearch.
 *
 * This resource is used by the Tattoo model's toSearchableArray() method
 * to define the document structure stored in the Elasticsearch index.
 *
 * Note: Uses ArtistResource (not ArtistIndexResource) for nested artist
 * to avoid circular references.
 *
 * @see \App\Models\Tattoo::toSearchableArray()
 * @see \App\Http\Resources\Elastic\ArtistResource
 */
class TattooIndexResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'artist_id' => $this->artist->id,
            'artist_name' => $this->artist->name ?? '',
            'artist_slug' => $this->artist->slug ?? '',
            'artist_image_uri' => $this->artist->primary_image->uri ?? '',
            'artist_username' => $this->artist->username ?? '',
            'artist_books_open' => $this->artist->settings->books_open ?? false,
            'artist_location_lat_long' => $this->artist->location_lat_long ?? null,
            'studio_id' => $this->studio->id ?? null,
            'studio_name' => $this->studio->name ?? '',
            'title' => $this->title,
            'description' => $this->description,
            'placement' => $this->placement,
            'duration' => $this->duration,
            'studio' => new StudioResource($this->studio),
            'primary_style' => $this->primary_style->name ?? '',
            'primary_subject' => $this->subject->name ?? '',
            'primary_image' => $this->primary_image ?? null,
            'images' => $this->images,
            'styles' => StyleResource::collection($this->styles),
            'tags' => $this->getTags(),
            'is_featured' => (bool) $this->is_featured,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    private function getTags()
    {
        if (isset($this->tags)) {
            // Only index approved tags (not pending ones)
            return $this->tags->where('is_pending', false)->pluck('name')->toArray();
        }

        return [];
    }
}
