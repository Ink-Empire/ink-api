<?php

namespace App\Http\Resources\Elastic;

use App\Enums\UserTypes;
use App\Http\Resources\StudioResource;
use App\Http\Resources\StyleResource;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resource for indexing Studios into Elasticsearch.
 *
 * This resource is used by the Studio model's toSearchableArray() method
 * to define the document structure stored in the Elasticsearch index.
 *
 * @see \App\Models\Studio::toSearchableArray()
 */
class StudioIndexResource extends JsonResource
{
    public function toArray($request)
    {
        // Format image to match artist primary_image structure
        $imageData = null;
        if ($this->image) {
            $imageData = [
                'uri' => $this->image->uri,
                'is_primary' => true,
            ];
        }

        return [
            'id' => $this->id,
            'about' => $this->about,
            'email' => $this->email,
            'location' => $this->location,
            'location_lat_long' => $this->location_lat_long,
            'name' => $this->name,
            'phone' => $this->phone,
            'slug' => $this->slug,
            'is_featured' => (bool) $this->is_featured,
            'is_demo' => (bool) $this->is_demo,
            'is_claimed' => (bool) ($this->is_claimed ?? false),
            'saved_count' => (int) ($this->saved_count ?? 0),
            'rating' => $this->rating,
            'styles' => StyleResource::collection($this->styles ?? []),
            'type' => UserTypes::STUDIO,
            'primary_image' => $imageData,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
