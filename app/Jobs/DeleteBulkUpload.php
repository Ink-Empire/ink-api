<?php

namespace App\Jobs;

use App\Enums\QueueNames;
use App\Models\BulkUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DeleteBulkUpload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300; // 5 minutes

    public function __construct(
        public int $bulkUploadId
    ) {
        $this->onQueue(QueueNames::BULK_UPLOAD);
    }

    public function handle(): void
    {
        $bulkUpload = BulkUpload::find($this->bulkUploadId);

        if (!$bulkUpload) {
            Log::warning("DeleteBulkUpload: BulkUpload not found: {$this->bulkUploadId}");
            return;
        }

        Log::info("DeleteBulkUpload: Starting cleanup for bulk upload {$this->bulkUploadId}");

        $deletedImages = 0;
        $deletedZip = false;

        try {
            // Delete ZIP from S3
            if ($bulkUpload->zip_path) {
                $bulkUpload->deleteZipFile();
                $deletedZip = true;
                Log::info("DeleteBulkUpload: Deleted ZIP file for {$this->bulkUploadId}");
            }

            // Delete associated images from S3 (only unpublished ones)
            $unpublishedItems = $bulkUpload->items()
                ->whereNotNull('image_id')
                ->where('is_published', false)
                ->with('image')
                ->get();

            foreach ($unpublishedItems as $item) {
                if ($item->image) {
                    // Delete from S3
                    if ($item->image->filename) {
                        Storage::disk('s3')->delete($item->image->filename);
                    }
                    // Delete image record
                    $item->image->delete();
                    $deletedImages++;
                }
            }

            // Delete all items and the upload record
            $itemCount = $bulkUpload->items()->count();
            $bulkUpload->items()->delete();
            $bulkUpload->delete();

            Log::info("DeleteBulkUpload: Completed cleanup for {$this->bulkUploadId} - ZIP: " . ($deletedZip ? 'yes' : 'no') . ", Images: {$deletedImages}, Items: {$itemCount}");

        } catch (\Exception $e) {
            Log::error("DeleteBulkUpload: Failed to delete bulk upload {$this->bulkUploadId}: " . $e->getMessage());
            throw $e;
        }
    }
}
