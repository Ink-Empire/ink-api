<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BulkUploadItemResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'bulk_upload_id' => $this->bulk_upload_id,

            // Group info
            'post_group_id' => $this->post_group_id,
            'is_primary_in_group' => $this->is_primary_in_group,
            'group_count' => $this->getGroupCount(),
            'group_items' => $this->when(
                $this->isPartOfGroup() && $this->is_primary_in_group,
                fn() => $this->getGroupItems()->map(fn($item) => [
                    'id' => $item->id,
                    'thumbnail_url' => $item->thumbnail_url,
                    'is_primary' => $item->is_primary_in_group,
                ])
            ),

            // Status
            'is_cataloged' => $this->is_cataloged,
            'is_processed' => $this->is_processed,
            'is_published' => $this->is_published,
            'is_skipped' => $this->is_skipped,
            'is_edited' => $this->is_edited,
            'is_ready_for_publish' => $this->isReadyForPublish(),

            // Image
            'image_id' => $this->image_id,
            'thumbnail_url' => $this->thumbnail_url,
            'tattoo_id' => $this->tattoo_id,

            // Original data
            'zip_path' => $this->zip_path,
            'filename' => $this->getFilenameFromPath(),
            'file_size_bytes' => $this->file_size_bytes,
            'original_caption' => $this->original_caption,
            'original_timestamp' => $this->original_timestamp?->toISOString(),

            // User-editable fields
            'title' => $this->title,
            'description' => $this->description,
            'placement_id' => $this->placement_id,
            'placement' => $this->whenLoaded('placement', fn() => [
                'id' => $this->placement->id,
                'name' => $this->placement->name,
                'slug' => $this->placement->slug,
            ]),
            'primary_style_id' => $this->primary_style_id,
            'primary_style' => $this->whenLoaded('primaryStyle', fn() => [
                'id' => $this->primaryStyle->id,
                'name' => $this->primaryStyle->name,
                'slug' => $this->primaryStyle->slug,
            ]),
            'additional_style_ids' => $this->additional_style_ids ?? [],

            // Tags
            'ai_suggested_tags' => $this->ai_suggested_tags ?? [],
            'ai_suggested_styles' => $this->ai_suggested_styles ?? [],
            'approved_tag_ids' => $this->approved_tag_ids ?? [],

            'sort_order' => $this->sort_order,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
