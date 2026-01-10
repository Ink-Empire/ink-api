<?php

namespace App\Jobs;

use App\Models\BulkUpload;
use App\Models\BulkUploadItem;
use App\Models\Image;
use App\Services\ImageService;
use App\Services\TagService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class ProcessBulkUploadBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 900; // 15 minutes

    public function __construct(
        public int $bulkUploadId,
        public int $batchSize = 200,
        public int $offset = 0
    ) {}

    public function handle(): void
    {
        $bulkUpload = BulkUpload::find($this->bulkUploadId);

        if (!$bulkUpload || $bulkUpload->isExpired()) {
            Log::warning("BulkUpload not found or expired: {$this->bulkUploadId}");
            return;
        }

        try {
            // Get items to process
            $items = $bulkUpload->unprocessedItems()
                ->orderBy('sort_order')
                ->offset($this->offset)
                ->limit($this->batchSize)
                ->get();

            if ($items->isEmpty()) {
                $bulkUpload->update(['status' => 'ready']);
                Log::info("No more items to process for bulk upload {$this->bulkUploadId}");
                return;
            }

            // Download ZIP to temp
            $tempZipPath = $this->downloadZipToTemp($bulkUpload);

            $zip = new ZipArchive();
            if ($zip->open($tempZipPath) !== true) {
                throw new \Exception('Failed to open ZIP file');
            }

            $processedCount = 0;

            foreach ($items as $item) {
                try {
                    $this->processItem($item, $zip, $bulkUpload);
                    $processedCount++;
                } catch (\Exception $e) {
                    Log::error("Failed to process item {$item->id}: " . $e->getMessage());
                    // Continue with next item
                }
            }

            $zip->close();
            @unlink($tempZipPath);

            // Update counts
            $bulkUpload->updateCounts();

            // Check if all items are processed
            $remainingCount = $bulkUpload->unprocessedItems()->count();
            if ($remainingCount === 0) {
                $bulkUpload->update(['status' => 'ready']);
                Log::info("Bulk upload {$this->bulkUploadId} is ready - all items processed");
            }
            // Keep status as 'processing' while batches are running

            Log::info("Processed {$processedCount} items for bulk upload {$this->bulkUploadId}");

        } catch (\Exception $e) {
            Log::error("Failed to process bulk upload batch {$this->bulkUploadId}: " . $e->getMessage());
            $bulkUpload->update(['status' => 'cataloged']); // Reset to allow retry
            throw $e;
        }
    }

    private function downloadZipToTemp(BulkUpload $bulkUpload): string
    {
        $zipPath = $bulkUpload->zip_path;
        $tempPath = sys_get_temp_dir() . '/' . uniqid('bulk_process_') . '.zip';

        // Use streaming to avoid loading entire ZIP into memory
        $stream = Storage::disk('s3')->readStream($zipPath);
        $tempFile = fopen($tempPath, 'w');
        stream_copy_to_stream($stream, $tempFile);
        fclose($tempFile);
        fclose($stream);

        return $tempPath;
    }

    private function processItem(BulkUploadItem $item, ZipArchive $zip, BulkUpload $bulkUpload): void
    {
        // Find file in ZIP
        $zipIndex = $zip->locateName($item->zip_path);

        // Try case-insensitive search if not found
        if ($zipIndex === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (strtolower($zip->getNameIndex($i)) === strtolower($item->zip_path)) {
                    $zipIndex = $i;
                    break;
                }
            }
        }

        if ($zipIndex === false) {
            Log::warning("File not found in ZIP: {$item->zip_path}");
            $item->update(['is_skipped' => true]);
            return;
        }

        // Extract file content
        $fileContent = $zip->getFromIndex($zipIndex);

        if ($fileContent === false) {
            Log::warning("Failed to extract file: {$item->zip_path}");
            $item->update(['is_skipped' => true]);
            return;
        }

        // Determine content type
        $extension = strtolower(pathinfo($item->zip_path, PATHINFO_EXTENSION));
        $contentType = match ($extension) {
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };

        // Generate filename for S3 with environment prefix
        $timestamp = now()->format('YmdHis');
        $random = Str::random(8);
        $baseFilename = "tattoo_{$bulkUpload->artist_id}_{$timestamp}_{$item->id}_{$random}.{$extension}";
        $filename = ImageService::prefixFilename($baseFilename);

        // Upload to S3
        $s3Path = $filename;
        Storage::disk('s3')->put($s3Path, $fileContent, [
            'visibility' => 'public',
            'ContentType' => $contentType,
            'CacheControl' => 'max-age=31536000',
        ]);

        // Create Image record
        $image = new Image([
            'filename' => $filename,
            'is_primary' => false,
        ]);
        $image->setUriAttribute($filename);
        $image->save();

        // Update item with image reference
        $item->update([
            'image_id' => $image->id,
            'is_processed' => true,
        ]);

        // Generate AI tags (optional, can be done separately)
        $this->generateAiTags($item, $image);
    }

    private function generateAiTags(BulkUploadItem $item, Image $image): void
    {
        // Skip AI tagging for now - can be implemented later
        // The suggestTagsForImage method doesn't exist yet
    }
}
