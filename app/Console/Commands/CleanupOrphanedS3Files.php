<?php

namespace App\Console\Commands;

use App\Models\Image;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupOrphanedS3Files extends Command
{
    protected $signature = 'cleanup:orphaned-s3-files
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--batch-size=100 : Number of S3 files to check per batch}
                            {--max-batches=10 : Maximum number of batches to process (0 for unlimited)}
                            {--prefix= : Only check files with this prefix}
                            {--skip-bulk-uploads : Skip the bulk-uploads folder (handled by separate command)}';

    protected $description = 'Find and remove S3 files that are not referenced by any image in the database';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');
        $maxBatches = (int) $this->option('max-batches');
        $prefix = $this->option('prefix') ?: '';
        $skipBulkUploads = $this->option('skip-bulk-uploads');

        if ($dryRun) {
            $this->info('DRY RUN - No files will actually be deleted');
        }

        $this->info("Scanning S3 for orphaned files...");
        $this->line("  Batch size: {$batchSize}");
        $this->line("  Max batches: " . ($maxBatches === 0 ? 'unlimited' : $maxBatches));
        if ($prefix) {
            $this->line("  Prefix filter: {$prefix}");
        }
        if ($skipBulkUploads) {
            $this->line("  Skipping bulk-uploads folder");
        }

        $s3 = Storage::disk('s3');
        $orphanedFiles = [];
        $checkedCount = 0;
        $batchCount = 0;
        $continuationToken = null;

        // Get S3 client for pagination
        $client = $s3->getClient();
        $bucket = config('filesystems.disks.s3.bucket');

        do {
            $params = [
                'Bucket' => $bucket,
                'MaxKeys' => $batchSize,
            ];

            if ($prefix) {
                $params['Prefix'] = $prefix;
            }

            if ($continuationToken) {
                $params['ContinuationToken'] = $continuationToken;
            }

            $result = $client->listObjectsV2($params);
            $contents = $result['Contents'] ?? [];

            if (empty($contents)) {
                break;
            }

            $filesToCheck = [];
            foreach ($contents as $object) {
                $key = $object['Key'];

                // Skip bulk-uploads folder if requested
                if ($skipBulkUploads && str_starts_with($key, 'bulk-uploads/')) {
                    continue;
                }

                // Skip directories
                if (str_ends_with($key, '/')) {
                    continue;
                }

                $filesToCheck[] = $key;
            }

            if (!empty($filesToCheck)) {
                // Batch check against database - look for filenames in the uri column
                $existingFiles = Image::whereIn('filename', $filesToCheck)->pluck('filename')->toArray();

                // Also check by extracting filename from uri (for older records)
                $s3Url = rtrim(config('filesystems.disks.s3.url', 'https://inked-in-images.s3.amazonaws.com'), '/');
                $urisToCheck = array_map(fn($f) => $s3Url . '/' . $f, $filesToCheck);
                $existingByUri = Image::whereIn('uri', $urisToCheck)->pluck('uri')->map(function ($uri) use ($s3Url) {
                    return str_replace($s3Url . '/', '', $uri);
                })->toArray();

                $allExisting = array_unique(array_merge($existingFiles, $existingByUri));

                foreach ($filesToCheck as $file) {
                    $checkedCount++;
                    if (!in_array($file, $allExisting)) {
                        $orphanedFiles[] = $file;
                        $this->line("  Orphaned: {$file}");
                    }
                }
            }

            $batchCount++;
            $continuationToken = $result['NextContinuationToken'] ?? null;

            $this->line("Batch {$batchCount}: checked " . count($filesToCheck) . " files, found " . count($orphanedFiles) . " orphaned so far");

        } while ($continuationToken && ($maxBatches === 0 || $batchCount < $maxBatches));

        $this->newLine();

        if (empty($orphanedFiles)) {
            $this->info("No orphaned files found after checking {$checkedCount} files.");
            return Command::SUCCESS;
        }

        $this->info("Found " . count($orphanedFiles) . " orphaned file(s) out of {$checkedCount} checked");

        if (!$dryRun) {
            $this->info("Deleting orphaned files...");

            $deletedCount = 0;
            $failedCount = 0;

            foreach ($orphanedFiles as $file) {
                try {
                    $s3->delete($file);
                    $deletedCount++;
                    $this->line("  Deleted: {$file}");
                } catch (\Exception $e) {
                    $failedCount++;
                    $this->error("  Failed to delete {$file}: " . $e->getMessage());
                }
            }

            $this->newLine();
            $this->info("Summary:");
            $this->line("  Files checked: {$checkedCount}");
            $this->line("  Files deleted: {$deletedCount}");
            if ($failedCount > 0) {
                $this->line("  Failed deletions: {$failedCount}");
            }

            Log::info("CleanupOrphanedS3Files: Checked {$checkedCount} files, deleted {$deletedCount} orphaned files, {$failedCount} failures");
        } else {
            $this->newLine();
            $this->info("DRY RUN Summary:");
            $this->line("  Files checked: {$checkedCount}");
            $this->line("  Files to delete: " . count($orphanedFiles));

            if ($this->option('verbose')) {
                $this->newLine();
                $this->info("Files that would be deleted:");
                foreach ($orphanedFiles as $file) {
                    $this->line("  {$file}");
                }
            }

            Log::info("CleanupOrphanedS3Files (dry run): Checked {$checkedCount} files, found " . count($orphanedFiles) . " orphaned files");
        }

        if ($continuationToken) {
            $this->newLine();
            $this->warn("More files remain in S3. Run again to continue processing.");
        }

        return Command::SUCCESS;
    }
}
