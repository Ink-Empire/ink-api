<?php

namespace App\Jobs;

use App\Enums\QueueNames;
use App\Jobs\GenerateAiTagsJob;
use App\Models\Artist;
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
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 600; // 10 minutes

    public function __construct(
        public int $bulkUploadId,
        public bool $publishAll = false,
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
            // Get items to publish, grouped by post_group_id
            $itemsQuery = $this->publishAll
                ? $bulkUpload->unpublishedItems()
                : $bulkUpload->readyItems();

            $items = $itemsQuery
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
            $tattooIds = [];

            foreach ($grouped as $groupKey => $groupItems) {
                try {
                    if (str_starts_with($groupKey, 'single_')) {
                        $tattoo = $this->publishSingleItem($groupItems->first(), $bulkUpload);
                    } else {
                        $tattoo = $this->publishGroupedItems($groupItems, $bulkUpload);
                    }
                    $tattooIds[] = $tattoo->id;
                    $publishedCount++;
                } catch (\Exception $e) {
                    Log::error("Failed to publish group {$groupKey}: " . $e->getMessage());
                    // Continue with next group
                }
            }

            // Batch index all published tattoos after all transactions have committed
            if (!empty($tattooIds)) {
                $this->batchIndex($tattooIds, $bulkUpload->artist_id);
            }

            // Update counts
            $bulkUpload->updateCounts();

            // Check if all items are either published or skipped
            $remainingItems = $bulkUpload->items()
                ->where('is_published', false)
                ->where('is_skipped', false)
                ->count();

            if ($remainingItems === 0) {
                $bulkUpload->update([
                    'status' => 'completed',
                    'completed_at' => now(),
                ]);

                $bulkUpload->deleteZipFile();
                Log::info("Deleted ZIP file for completed bulk upload {$this->bulkUploadId}");
            } else {
                $bulkUpload->update(['status' => 'incomplete']);
                Log::info("Bulk upload {$this->bulkUploadId} marked incomplete - {$remainingItems} items remaining");
            }

            Log::info("Published {$publishedCount} tattoos from bulk upload {$this->bulkUploadId}");

        } catch (\Exception $e) {
            Log::error("Failed to publish bulk upload items {$this->bulkUploadId}: " . $e->getMessage());
            throw $e;
        }
    }

    private function publishSingleItem(BulkUploadItem $item, BulkUpload $bulkUpload): Tattoo
    {
        $artist = $bulkUpload->artist;

        return DB::transaction(function () use ($item, $bulkUpload, $artist) {
            $tattoo = Tattoo::create([
                'artist_id' => $bulkUpload->artist_id,
                'studio_id' => $artist?->primary_studio?->id,
                'title' => $item->title,
                'description' => $item->description,
                'placement' => $item->placement?->name,
                'primary_style_id' => $item->primary_style_id,
                'primary_image_id' => $item->image_id,
            ]);

            if ($item->image_id) {
                $tattoo->images()->attach($item->image_id);
            }

            $styleIds = $item->getAllStyleIds();
            if (!empty($styleIds)) {
                $tattoo->styles()->sync($styleIds);
            }

            $tagIds = $item->getAllTagIds();
            if (!empty($tagIds)) {
                $tattoo->tags()->sync($tagIds);
            }

            $item->update([
                'is_published' => true,
                'tattoo_id' => $tattoo->id,
            ]);

            return $tattoo;
        });
    }

    private function publishGroupedItems(Collection $items, BulkUpload $bulkUpload): Tattoo
    {
        $artist = $bulkUpload->artist;
        $primaryItem = $items->firstWhere('is_primary_in_group', true) ?? $items->first();

        return DB::transaction(function () use ($items, $bulkUpload, $artist, $primaryItem) {
            $tattoo = Tattoo::create([
                'artist_id' => $bulkUpload->artist_id,
                'studio_id' => $artist?->primary_studio?->id,
                'title' => $primaryItem->title,
                'description' => $primaryItem->description,
                'placement' => $primaryItem->placement?->name,
                'primary_style_id' => $primaryItem->primary_style_id,
                'primary_image_id' => $primaryItem->image_id,
            ]);

            $imageIds = $items->pluck('image_id')->filter()->unique()->toArray();
            if (!empty($imageIds)) {
                $tattoo->images()->attach($imageIds);
            }

            $styleIds = $primaryItem->getAllStyleIds();
            if (!empty($styleIds)) {
                $tattoo->styles()->sync($styleIds);
            }

            $tagIds = $primaryItem->getAllTagIds();
            if (!empty($tagIds)) {
                $tattoo->tags()->sync($tagIds);
            }

            foreach ($items as $item) {
                $item->update([
                    'is_published' => true,
                    'tattoo_id' => $tattoo->id,
                ]);
            }

            return $tattoo;
        });
    }

    private function batchIndex(array $tattooIds, int $artistId): void
    {
        try {
            $tattoos = Tattoo::with([
                'tags', 'styles', 'images', 'artist', 'studio', 'primary_style', 'primary_image',
            ])->whereIn('id', $tattooIds)->get();

            $tattoos->searchable();

            Artist::find($artistId)?->searchable();

            Log::info("Batch indexed {$tattoos->count()} tattoos for bulk upload {$this->bulkUploadId}");
        } catch (\Exception $e) {
            Log::error("Batch ES indexing failed for bulk upload {$this->bulkUploadId}, falling back to individual jobs: " . $e->getMessage());

            // Fall back to individual IndexTattooJob dispatches
            foreach ($tattooIds as $tattooId) {
                IndexTattooJob::dispatch($tattooId, false);
            }
            // Re-index artist once
            Artist::find($artistId)?->searchable();
        }

        // Dispatch AI tag generation for each published tattoo
        foreach ($tattooIds as $tattooId) {
            GenerateAiTagsJob::dispatch($tattooId);
        }
    }
}
