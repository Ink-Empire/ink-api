<?php

namespace App\Http\Resources\Elastic;

use App\Enums\UserTypes;
use App\Http\Resources\StudioResource;
use App\Http\Resources\StyleResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource for indexing Artists into Elasticsearch.
 *
 * This resource is used by the Artist model's toSearchableArray() method
 * to define the document structure stored in the Elasticsearch index.
 *
 * Note: Tattoos are NOT included in the artist index. They are fetched
 * separately from the tattoos index when needed.
 *
 * @see \App\Models\Artist::toSearchableArray()
 */
class ArtistIndexResource extends JsonResource
{
    protected $primaryStudio;

    public function __construct($resource, $primaryStudio = null)
    {
        parent::__construct($resource);
        $this->primaryStudio = $primaryStudio;
    }

    public function toArray($request)
    {
        // Use the explicitly passed primary studio, or fall back to the attribute.
        // Studio accounts own their venue rather than joining it through the
        // members pivot, so fall back to the owned studio for them.
        $studio = $this->primaryStudio
            ?? $this->resource->primary_studio
            ?? $this->resource->ownedStudio;

        return [
            'id' => $this->id,
            'about' => $this->about,
            'email' => $this->email,
            'location' => $this->location,
            'location_lat_long' => $this->location_lat_long,
            'name' => $this->name,
            'phone' => $this->phone,
            'slug' => $this->slug,
            'studio' => $studio ? new StudioResource($studio) : null,
            'studio_name' => $studio?->name,
            'is_featured' => (bool) $this->is_featured,
            'is_demo' => (bool) $this->is_demo,
            'saved_count' => (int) ($this->saved_count ?? 0),
            'styles' => StyleResource::collection($this->styles ?? []),
            // The artists index holds both artists and studio accounts, and the
            // frontend picks its card and route from this field. Deriving it
            // from type_id stops a studio being rendered as an artist.
            'type' => $this->type_id === UserTypes::STUDIO_TYPE_ID
                ? UserTypes::STUDIO
                : UserTypes::ARTIST,
            // Every account in this index is a real signup; the unclaimed stubs
            // built from Google Places are never indexed here. Without this the
            // studio card falls back to its "hasn't joined yet" layout.
            'is_claimed' => $this->type_id === UserTypes::STUDIO_TYPE_ID,
            'primary_image' => $this->primary_image ?? null,
            'username' => $this->username,
            'settings' => $this->settings ? $this->settings->toArray() : [],
            'social_media_links' => $this->socialMediaLinks?->map(fn($link) => [
                'platform' => $link->platform,
                'username' => $link->username,
                'url' => $link->url,
            ])->values()->toArray() ?? [],
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
