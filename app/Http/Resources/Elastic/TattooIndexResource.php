<?php

namespace App\Http\Resources\Elastic;

use App\Http\Resources\StudioResource;
use App\Http\Resources\StyleResource;
use App\Enums\ArtistTattooApprovalStatus;
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
            'artist_id' => $this->artist?->id,
            'artist_name' => $this->artist?->name ?? '',
            'artist_slug' => $this->artist?->slug ?? '',
            'artist_image_uri' => $this->artist?->primary_image?->uri ?? '',
            'artist_username' => $this->artist?->username ?? '',
            'artist_books_open' => $this->artist?->settings?->books_open ?? false,
            'artist_location' => $this->artist?->location ?? '',
            'artist_location_lat_long' => $this->artist?->location_lat_long ?? null,
            'studio_id' => $this->studio?->id ?? null,
            'studio_name' => $this->studio?->name ?? '',
            'title' => $this->title,
            'description' => $this->description,
            'placement' => $this->placement,
            'duration' => $this->duration,
            'studio' => $this->studio ? new StudioResource($this->studio) : null,
            'primary_style' => $this->primary_style?->name ?? '',
            'primary_subject' => $this->subject?->name ?? '',
            'primary_image' => $this->primary_image ?? null,
            'images' => $this->images,
            'styles' => StyleResource::collection($this->styles),
            'tags' => $this->getTags(),
            'is_featured' => (bool) $this->is_featured,
            'is_demo' => (bool) $this->is_demo,
            'is_visible' => (bool) $this->is_visible,
            'saved_count' => (int) ($this->saved_count ?? 0),
            'created_at' => $this->created_at?->toIso8601String(),
            'uploaded_by_user_id' => $this->uploaded_by_user_id,
            'uploader_name' => $this->uploader?->name ?? '',
            'uploader_slug' => $this->uploader?->slug ?? '',
            'uploader_image_uri' => $this->uploader?->image?->uri ?? '',
            'uploader_username' => $this->uploader?->username ?? '',
            'approval_status' => $this->approval_status ?? ArtistTattooApprovalStatus::APPROVED,
            'is_user_upload' => $this->uploaded_by_user_id !== null && $this->uploaded_by_user_id !== $this->artist_id,
            'attributed_artist_name' => $this->attributed_artist_name ?? '',
            'attributed_studio_name' => $this->attributed_studio_name ?? '',
            'attributed_location' => $this->attributed_location ?? '',
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
