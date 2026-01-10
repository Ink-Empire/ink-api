<?php

namespace App\Jobs;

use App\Enums\QueueNames;
use App\Models\BulkUpload;
use App\Models\BulkUploadItem;
use App\Models\Tattoo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PublishBulkUploadItems implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600; // 10 minutes

    public function __construct(
        public int $bulkUploadId
    ) {
        $this->onQueue(QueueNames::BULK_UPLOAD);
    }

    public function handle(): void
    {
        $bulkUpload = BulkUpload::find($this->bulkUploadId);

        if (!$bulkUpload) {
            Log::warning("BulkUpload not found: {$this->bulkUploadId}");
            return;
        }

        try {
            // Get all ready items, grouped by post_group_id
            $items = $bulkUpload->readyItems()
                ->whereNotNull('primary_style_id') // Require at least a style
                ->orderBy('post_group_id')
                ->orderBy('is_primary_in_group', 'desc')
                ->orderBy('sort_order')
                ->get();

            if ($items->isEmpty()) {
                Log::info("No items ready to publish for bulk upload {$this->bulkUploadId}");
                return;
            }

            // Group items by post_group_id
            $grouped = $items->groupBy(function ($item) {
                return $item->post_group_id ?? 'single_' . $item->id;
            });

            $publishedCount = 0;

            foreach ($grouped as $groupKey => $groupItems) {
                try {
                    if (str_starts_with($groupKey, 'single_')) {
                        // Single image tattoo
                        $this->publishSingleItem($groupItems->first(), $bulkUpload);
                    } else {
                        // Carousel - multiple images, one tattoo
                        $this->publishGroupedItems($groupItems, $bulkUpload);
                    }
                    $publishedCount++;
                } catch (\Exception $e) {
                    Log::error("Failed to publish group {$groupKey}: " . $e->getMessage());
                    // Continue with next group
                }
            }

            // Update counts
            $bulkUpload->updateCounts();

            // Check if all done
            if ($bulkUpload->readyItems()->count() === 0) {
                $bulkUpload->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);

                // Clean up the ZIP file - no longer needed after publishing
                $bulkUpload->deleteZipFile();
                Log::info("Deleted ZIP file for completed bulk upload {$this->bulkUploadId}");
            }

            Log::info("Published {$publishedCount} tattoos from bulk upload {$this->bulkUploadId}");

        } catch (\Exception $e) {
            Log::error("Failed to publish bulk upload items {$this->bulkUploadId}: " . $e->getMessage());
            throw $e;
        }
    }

    private function publishSingleItem(BulkUploadItem $item, BulkUpload $bulkUpload): void
    {
        DB::transaction(function () use ($item, $bulkUpload) {
            // Create tattoo
            $tattoo = Tattoo::create([
                'artist_id' => $bulkUpload->artist_id,
                'title' => $item->title,
                'description' => $item->description,
                'placement' => $item->placement?->name,
                'primary_style_id' => $item->primary_style_id,
                'primary_image_id' => $item->image_id,
            ]);

            // Attach image
            if ($item->image_id) {
                $tattoo->images()->attach($item->image_id);
            }

            // Attach styles
            $styleIds = $item->getAllStyleIds();
            if (!empty($styleIds)) {
                $tattoo->styles()->sync($styleIds);
            }

            // Attach tags
            $tagIds = $item->getAllTagIds();
            if (!empty($tagIds)) {
                $tattoo->tags()->sync($tagIds);
            }

            // Mark item as published
            $item->update([
                'is_published' => true,
                'tattoo_id' => $tattoo->id,
            ]);

            // Index to Elasticsearch
            $tattoo->searchable();
        });
    }

    private function publishGroupedItems(Collection $items, BulkUpload $bulkUpload): void
    {
        DB::transaction(function () use ($items, $bulkUpload) {
            // Get primary item (first in group)
            $primaryItem = $items->firstWhere('is_primary_in_group', true) ?? $items->first();

            // Create tattoo from primary item's metadata
            $tattoo = Tattoo::create([
                'artist_id' => $bulkUpload->artist_id,
                'title' => $primaryItem->title,
                'description' => $primaryItem->description,
                'placement' => $primaryItem->placement?->name,
                'primary_style_id' => $primaryItem->primary_style_id,
                'primary_image_id' => $primaryItem->image_id,
            ]);

            // Attach all images from the group
            $imageIds = $items->pluck('image_id')->filter()->unique()->toArray();
            if (!empty($imageIds)) {
                $tattoo->images()->attach($imageIds);
            }

            // Attach styles from primary item
            $styleIds = $primaryItem->getAllStyleIds();
            if (!empty($styleIds)) {
                $tattoo->styles()->sync($styleIds);
            }

            // Attach tags from primary item
            $tagIds = $primaryItem->getAllTagIds();
            if (!empty($tagIds)) {
                $tattoo->tags()->sync($tagIds);
            }

            // Mark all items in group as published
            foreach ($items as $item) {
                $item->update([
                    'is_published' => true,
                    'tattoo_id' => $tattoo->id,
                ]);
            }

            // Index to Elasticsearch
            $tattoo->searchable();
        });
    }
}
