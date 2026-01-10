<?php

namespace App\Jobs;

use App\Enums\QueueNames;
use App\Models\BulkUpload;
use App\Models\BulkUploadItem;
use App\Services\InstagramExportParser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

class ScanBulkUploadZip implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600; // 10 minutes
    public $queue = QueueNames::BULK_UPLOAD;

    public function __construct(
        public int $bulkUploadId
    ) {}

    public function handle(InstagramExportParser $instagramParser): void
    {
        $bulkUpload = BulkUpload::find($this->bulkUploadId);

        if (!$bulkUpload) {
            Log::warning("BulkUpload not found: {$this->bulkUploadId}");
            return;
        }

        try {
            // Download ZIP from S3 to temp file
            $tempPath = $this->downloadZipToTemp($bulkUpload);

            $zip = new ZipArchive();
            if ($zip->open($tempPath) !== true) {
                throw new \Exception('Failed to open ZIP file');
            }

            // Detect if this is an Instagram export
            $isInstagram = $bulkUpload->source === 'instagram' || $this->detectInstagramExport($zip);
            $instagramData = [];

            if ($isInstagram) {
                $instagramData = $instagramParser->parseFromZip($zip);
                $bulkUpload->update(['source' => 'instagram']);
            }

            // Scan all files and create items
            $items = $this->catalogImages($bulkUpload, $zip, $instagramData);

            $zip->close();

            // Clean up temp file
            @unlink($tempPath);

            // Update bulk upload
            $totalItems = count($items);
            $bulkUpload->update([
                'status' => 'processing',
                'total_images' => $totalItems,
                'cataloged_images' => $totalItems,
            ]);

            Log::info("Scanned bulk upload {$this->bulkUploadId}: {$totalItems} images found");

            // Auto-dispatch processing jobs in batches of 25
            $batchSize = 25;
            $totalBatches = ceil($totalItems / $batchSize);

            for ($i = 0; $i < $totalBatches; $i++) {
                ProcessBulkUploadBatch::dispatch(
                    $this->bulkUploadId,
                    $batchSize,
                    $i * $batchSize
                )->delay(now()->addSeconds($i * 2)); // Stagger batches slightly
            }

            Log::info("Dispatched {$totalBatches} processing batches for bulk upload {$this->bulkUploadId}");

        } catch (\Exception $e) {
            Log::error("Failed to scan bulk upload {$this->bulkUploadId}: " . $e->getMessage());
            $bulkUpload->markFailed($e->getMessage());
            throw $e;
        }
    }

    private function downloadZipToTemp(BulkUpload $bulkUpload): string
    {
        $zipPath = $bulkUpload->zip_path;
        $tempPath = sys_get_temp_dir() . '/' . uniqid('bulk_upload_') . '.zip';

        // Use streaming to avoid loading entire ZIP into memory
        $stream = Storage::disk('s3')->readStream($zipPath);
        $tempFile = fopen($tempPath, 'w');
        stream_copy_to_stream($stream, $tempFile);
        fclose($tempFile);
        fclose($stream);

        return $tempPath;
    }

    private function detectInstagramExport(ZipArchive $zip): bool
    {
        // Look for Instagram's typical JSON files
        $instagramIndicators = [
            'content/posts_1.json',
            'content/posts.json',
            'media/posts/',
            'your_instagram_activity/content/posts_1.json',
        ];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            foreach ($instagramIndicators as $indicator) {
                if (str_contains(strtolower($filename), strtolower($indicator))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function catalogImages(BulkUpload $bulkUpload, ZipArchive $zip, array $instagramData): array
    {
        $allowedExtensions = config('bulk_upload.allowed_extensions', ['jpg', 'jpeg', 'png', 'webp', 'gif']);
        $items = [];
        $sortOrder = 0;

        Log::info("Scanning ZIP with {$zip->numFiles} entries for bulk upload {$bulkUpload->id}");

        // Build a map of file paths to Instagram metadata
        $instagramMap = [];
        foreach ($instagramData as $post) {
            foreach ($post['media'] as $index => $media) {
                $normalizedPath = $this->normalizePath($media['uri']);
                $instagramMap[$normalizedPath] = [
                    'post_group_id' => $post['group_id'],
                    'is_primary' => $index === 0,
                    'caption' => $post['caption'],
                    'timestamp' => $post['timestamp'],
                ];
            }
        }

        // Scan ZIP for image files
        $skippedCount = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            // Skip directories
            if (str_ends_with($filename, '/')) {
                continue;
            }

            // Skip non-image files
            if (!in_array($ext, $allowedExtensions)) {
                $skippedCount++;
                if ($skippedCount <= 5) {
                    Log::debug("Skipping non-image file: {$filename} (ext: {$ext})");
                }
                continue;
            }

            // Get file stats
            $stat = $zip->statIndex($i);

            // Check for Instagram metadata
            $normalizedFilename = $this->normalizePath($filename);
            $instagramInfo = $instagramMap[$normalizedFilename] ?? null;

            $itemData = [
                'bulk_upload_id' => $bulkUpload->id,
                'zip_path' => $filename,
                'file_size_bytes' => $stat['size'] ?? null,
                'sort_order' => $sortOrder++,
                'is_cataloged' => true,
                'is_processed' => false,
            ];

            if ($instagramInfo) {
                $itemData['post_group_id'] = $instagramInfo['post_group_id'];
                $itemData['is_primary_in_group'] = $instagramInfo['is_primary'];
                $itemData['original_caption'] = $instagramInfo['caption'];
                $itemData['original_timestamp'] = $instagramInfo['timestamp'];
                // Use caption as initial description
                $itemData['description'] = $this->cleanCaption($instagramInfo['caption']);
            }

            $items[] = BulkUploadItem::create($itemData);
        }

        Log::info("Cataloged " . count($items) . " images, skipped {$skippedCount} non-image files for bulk upload {$bulkUpload->id}");

        return $items;
    }

    private function normalizePath(string $path): string
    {
        // Remove leading slashes and normalize separators
        $path = ltrim($path, '/\\');
        $path = str_replace('\\', '/', $path);
        return strtolower($path);
    }

    private function cleanCaption(?string $caption): ?string
    {
        if (!$caption) {
            return null;
        }

        // Remove hashtags
        $cleaned = preg_replace('/#\w+/', '', $caption);
        // Remove multiple spaces
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        // Trim
        $cleaned = trim($cleaned);

        return $cleaned ?: null;
    }
}
