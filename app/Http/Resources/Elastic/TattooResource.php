<?php

namespace App\Http\Resources\Elastic;

use App\Enums\ArtistTattooApprovalStatus;
use App\Http\Resources\Elastic\ArtistResource;
use App\Http\Resources\StudioResource;
use Illuminate\Http\Resources\Json\JsonResource;

class TattooResource extends JsonResource
{
    public function toArray($request)
    {
        try {
            return [
                'id' => $this->id,
                'artist_id' => $this->artist?->id,
                'artist_slug' => $this->artist?->slug,
                'artist_image_uri' => $this->artist?->primary_image?->uri ?? '',
                'studio_id' => $this->studio?->id ?? null,
                'title' => $this->title,
                'description' => $this->description,
                'placement' => $this->placement,
                'duration' => $this->duration,
                'studio' => $this->studio ? new StudioResource($this->studio) : null,
                'primary_style' => $this->primary_style?->name ?? "",
                'primary_subject' => $this->subject?->name ?? "",
                'primary_image' => $this->primary_image ? [
                    'uri' => $this->primary_image->uri,
                    'edit_params' => $this->primary_image->edit_params ?? null,
                ] : null,
                'images' => $this->images ?? [],
                'styles' => $this->styles ?? [],
                'tags' => $this->tags ?? [],
                'is_featured' => (int)$this->is_featured,
                'uploaded_by_user_id' => $this->uploaded_by_user_id ?? null,
                'uploader_name' => $this->uploader?->name ?? '',
                'uploader_slug' => $this->uploader?->slug ?? '',
                'uploader_image_uri' => $this->uploader?->image?->uri ?? '',
                'approval_status' => $this->approval_status ?? ArtistTattooApprovalStatus::APPROVED,
                'is_visible' => (bool) ($this->is_visible ?? true),
                'is_user_upload' => $this->uploaded_by_user_id !== null && $this->uploaded_by_user_id !== $this->artist?->id,
                'attributed_artist_name' => $this->attributed_artist_name ?? '',
                'attributed_studio_name' => $this->attributed_studio_name ?? '',
                'attributed_location' => $this->attributed_location ?? '',
                'post_type' => $this->post_type ?? 'portfolio',
                'flash_price' => $this->flash_price ? (float) $this->flash_price : null,
                'flash_size' => $this->flash_size ?? null,
            ];
        } catch (\Exception $e) {
            \Log::error([
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);
        }
    }
}
