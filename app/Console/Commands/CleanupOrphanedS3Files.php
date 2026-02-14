<?php

namespace App\Console\Commands;

use App\Models\Image;
use Carbon\Carbon;
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
                            {--target-env= : Environment prefix to process (e.g. local, dev, production)}
                            {--min-age=24 : Minimum age in hours before a file can be deleted}
                            {--delete-limit=50 : Maximum number of files to delete per run}
                            {--skip-bulk-uploads : Skip the bulk-uploads folder (handled by separate command)}';

    protected $description = 'Find and remove S3 files that are not referenced by any image in the database';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $batchSize = (int) $this->option('batch-size');
        $maxBatches = (int) $this->option('max-batches');
        $env = $this->option('target-env');
        $minAgeHours = (int) $this->option('min-age');
        $deleteLimit = (int) $this->option('delete-limit');
        $skipBulkUploads = $this->option('skip-bulk-uploads');

        if (!$env) {
            $this->error('The --target-env option is required (e.g. --target-env=production)');

            return Command::FAILURE;
        }

        $prefix = $env . '-';
        $ageCutoff = Carbon::now()->subHours($minAgeHours);

        if ($dryRun) {
            $this->info('DRY RUN - No files will actually be deleted');
        }

        $this->info("Scanning S3 for orphaned files...");
        $this->line("  Environment: {$env} (prefix: {$prefix})");
        $this->line("  Min age: {$minAgeHours} hours (before " . $ageCutoff->toDateTimeString() . ')');
        $this->line("  Delete limit: {$deleteLimit}");
        $this->line("  Batch size: {$batchSize}");
        $this->line("  Max batches: " . ($maxBatches === 0 ? 'unlimited' : $maxBatches));
        if ($skipBulkUploads) {
            $this->line("  Skipping bulk-uploads/ folder");
        }
        $this->line("  Skipping fixtures/ folder");

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

                // Skip directories
                if (str_ends_with($key, '/')) {
                    continue;
                }

                // Skip bulk-uploads folder if requested
                if ($skipBulkUploads && str_starts_with($key, 'bulk-uploads/')) {
                    continue;
                }

                // Skip fixtures folder (CI test data)
                if (str_starts_with($key, 'fixtures/')) {
                    continue;
                }

                // Skip files newer than the minimum age (protects presigned URL uploads in progress)
                $lastModified = Carbon::parse($object['LastModified']);
                if ($lastModified->isAfter($ageCutoff)) {
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
            $this->info("Deleting orphaned files (limit: {$deleteLimit})...");

            $deletedCount = 0;
            $failedCount = 0;
            $skippedCount = 0;

            foreach ($orphanedFiles as $file) {
                if ($deletedCount >= $deleteLimit) {
                    $skippedCount = count($orphanedFiles) - $deletedCount - $failedCount;
                    $this->warn("Delete limit of {$deleteLimit} reached. {$skippedCount} orphaned file(s) remaining.");
                    break;
                }

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
            if ($skippedCount > 0) {
                $this->line("  Remaining (hit delete limit): {$skippedCount}");
            }

            Log::info("CleanupOrphanedS3Files: Checked {$checkedCount} files, deleted {$deletedCount} orphaned files, {$failedCount} failures, {$skippedCount} remaining");
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
