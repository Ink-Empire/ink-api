<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class BulkUploadResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'status' => $this->status,
            'original_filename' => $this->original_filename,
            'total_images' => $this->total_images,
            'cataloged_images' => $this->cataloged_images,
            'processed_images' => $this->processed_images,
            'published_images' => $this->published_images,
            'unprocessed_count' => $this->cataloged_images - $this->processed_images,
            'ready_count' => $this->readyItems()->primaryInGroup()->count(),
            'zip_size_bytes' => $this->zip_size_bytes,
            'zip_expires_at' => $this->zip_expires_at?->toISOString(),
            'is_expired' => $this->isExpired(),
            'can_process' => $this->canProcess(),
            'can_publish' => $this->canPublish(),
            'error_message' => $this->error_message,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'completed_at' => $this->completed_at?->toISOString(),
        ];
    }
}
