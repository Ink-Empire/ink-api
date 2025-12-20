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
            'artist_username' => $this->artist->username ?? '',
            'artist_books_open' => $this->artist->settings->books_open ?? false,
            'studio_id' => $this->studio->id ?? null,
            'studio_name' => $this->studio->name ?? '',
            'title' => $this->title,
            'description' => $this->description,
            'placement' => $this->placement,
            'artist' => new ArtistResource($this->artist),
            'studio' => new StudioResource($this->studio),
            'primary_style' => $this->primary_style->name ?? '',
            'primary_subject' => $this->subject->name ?? '',
            'primary_image' => $this->primary_image ?? null,
            'images' => $this->images,
            'styles' => StyleResource::collection($this->styles),
            'tags' => $this->getTags(),
            'is_featured' => (bool) $this->is_featured,
        ];
    }

    private function getTags()
    {
        if (isset($this->tags)) {
            return $this->tags->pluck('name')->toArray();
        }

        return [];
    }
}
