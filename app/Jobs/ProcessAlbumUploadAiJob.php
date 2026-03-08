<?php

namespace App\Jobs;

use App\Enums\QueueNames;
use App\Models\BulkUpload;
use App\Notifications\BulkUploadReadyNotification;
use App\Services\StyleService;
use App\Services\TagService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAlbumUploadAiJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 900;

    public function __construct(
        public int $bulkUploadId
    ) {
        $this->onQueue(QueueNames::BULK_UPLOAD);
    }

    public function handle(TagService $tagService, StyleService $styleService): void
    {
        $bulkUpload = BulkUpload::find($this->bulkUploadId);

        if (!$bulkUpload) {
            Log::warning('ProcessAlbumUploadAiJob: bulk upload not found', [
                'bulk_upload_id' => $this->bulkUploadId,
            ]);
            return;
        }

        $items = $bulkUpload->items()->with('image')->get();
        $processedCount = 0;

        foreach ($items as $item) {
            if (!$item->image?->uri) {
                continue;
            }

            $imageUrl = $item->image->uri;

            try {
                $suggestedTags = $tagService->suggestTagsForImages([$imageUrl]);
                $tagData = collect($suggestedTags)->map(function ($tag) {
                    return [
                        'id' => $tag->id ?? null,
                        'name' => $tag->name,
                        'is_new_suggestion' => $tag->is_new_suggestion ?? false,
                    ];
                })->toArray();

                $item->update(['ai_suggested_tags' => $tagData]);
            } catch (\Throwable $e) {
                Log::error('ProcessAlbumUploadAiJob: tag suggestion failed', [
                    'item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $suggestedStyles = $styleService->suggestStylesForImages([$imageUrl]);
                $styleData = collect($suggestedStyles)->map(function ($style) {
                    return [
                        'id' => $style->id,
                        'name' => $style->name,
                    ];
                })->toArray();

                $item->update(['ai_suggested_styles' => $styleData]);
            } catch (\Throwable $e) {
                Log::error('ProcessAlbumUploadAiJob: style suggestion failed', [
                    'item_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $processedCount++;
        }

        $bulkUpload->update(['status' => 'ready']);

        Log::info('ProcessAlbumUploadAiJob completed', [
            'bulk_upload_id' => $this->bulkUploadId,
            'processed_count' => $processedCount,
        ]);

        $artist = $bulkUpload->artist;
        if ($artist) {
            try {
                $artist->notify(new BulkUploadReadyNotification($bulkUpload, $processedCount));
            } catch (\Throwable $e) {
                Log::warning('ProcessAlbumUploadAiJob: notification failed', [
                    'bulk_upload_id' => $this->bulkUploadId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
