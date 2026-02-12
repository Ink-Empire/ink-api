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

    public function __construct(
        public int $bulkUploadId
    ) {
        $this->onQueue(QueueNames::BULK_UPLOAD);
    }

    public function handle(InstagramExportParser $instagramParser): void
    {
        $bulkUpload = BulkUpload::find($this->bulkUploadId);

        if (!$bulkUpload) {
            Log::warning("BulkUpload not found: {$this->bulkUploadId}");
            return;
        }

        try {
            // Clean up any items from prior attempts (retries)
            $bulkUpload->items()->delete();

            // Download ZIP from S3 to temp file
            $tempPath = $this->downloadZipToTemp($bulkUpload);

            $zip = new ZipArchive();
            if ($zip->open($tempPath) !== true) {
                throw new \Exception('Failed to open ZIP file');
            }

            // Detect the type of ZIP (Instagram export vs simple images)
            $zipType = $this->detectZipType($zip);
            Log::info("Detected ZIP type for bulk upload {$this->bulkUploadId}: {$zipType}");

            $instagramData = [];

            if ($zipType === 'instagram') {
                $instagramData = $instagramParser->parseFromZip($zip);
                $bulkUpload->update(['source' => 'instagram']);
            } else {
                // Simple image zip
                $bulkUpload->update(['source' => 'images']);
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

            Log::info("Scanned bulk upload {$this->bulkUploadId}: {$totalItems} images found (source: {$zipType})");

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

    private function detectZipType(ZipArchive $zip): string
    {
        // Look for Instagram's typical JSON files
        $instagramIndicators = [
            'content/posts_1.json',
            'content/posts.json',
            'media/posts/',
            'your_instagram_activity/content/posts_1.json',
        ];

        $allowedExtensions = config('bulk_upload.allowed_extensions', ['jpg', 'jpeg', 'png', 'webp', 'gif']);
        $imageCount = 0;
        $hasInstagramIndicator = false;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $lowerFilename = strtolower($filename);

            // Check for Instagram indicators
            foreach ($instagramIndicators as $indicator) {
                if (str_contains($lowerFilename, strtolower($indicator))) {
                    $hasInstagramIndicator = true;
                    break;
                }
            }

            // Count image files
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExtensions)) {
                $imageCount++;
            }
        }

        if ($hasInstagramIndicator) {
            return 'instagram';
        }

        // If we have images but no Instagram indicators, it's a simple image zip
        if ($imageCount > 0) {
            return 'images';
        }

        // No images found - will fail later during cataloging
        return 'unknown';
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

            // Skip macOS resource fork files and __MACOSX directory
            $basename = basename($filename);
            if (str_starts_with($basename, '._') || str_contains($filename, '__MACOSX/')) {
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
            } else {
                // For simple image zips, extract a title from the filename
                $itemData['title'] = $this->extractTitleFromFilename($filename);
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

    private function extractTitleFromFilename(string $filepath): ?string
    {
        // Get just the filename without path
        $filename = pathinfo($filepath, PATHINFO_FILENAME);

        if (!$filename) {
            return null;
        }

        // Replace common separators with spaces
        $title = str_replace(['_', '-'], ' ', $filename);

        // Remove common suffixes like unsplash, numbers, etc.
        $title = preg_replace('/\s*(unsplash|pexels|pixabay|\d{10,})\s*/i', ' ', $title);

        // Remove multiple spaces
        $title = preg_replace('/\s+/', ' ', $title);

        // Title case and trim
        $title = trim(ucwords(strtolower($title)));

        return $title ?: null;
    }
}
