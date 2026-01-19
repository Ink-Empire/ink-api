<?php

namespace App\Console\Commands;

use App\Models\BulkUpload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CleanupExpiredBulkUploads extends Command
{
    protected $signature = 'cleanup:expired-bulk-uploads
                            {--dry-run : Show what would be deleted without actually deleting}
                            {--include-completed : Also clean up completed uploads older than expiry}';

    protected $description = 'Clean up expired bulk uploads, their ZIP files, and unpublished images';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $includeCompleted = $this->option('include-completed');

        if ($dryRun) {
            $this->info('DRY RUN - No files will actually be deleted');
        }

        // Find expired bulk uploads
        $query = BulkUpload::where('zip_expires_at', '<', now());

        if (!$includeCompleted) {
            $query->where('status', '!=', 'completed');
        }

        $expiredUploads = $query->get();

        if ($expiredUploads->isEmpty()) {
            $this->info('No expired bulk uploads found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$expiredUploads->count()} expired bulk upload(s)");

        $deletedZips = 0;
        $deletedImages = 0;
        $deletedRecords = 0;

        foreach ($expiredUploads as $upload) {
            $this->line("Processing bulk upload #{$upload->id} (status: {$upload->status}, expired: {$upload->zip_expires_at})");

            // Delete ZIP file if it exists
            if ($upload->zip_path) {
                if ($dryRun) {
                    $this->line("  Would delete ZIP: {$upload->zip_path}");
                } else {
                    $upload->deleteZipFile();
                    $this->line("  Deleted ZIP: {$upload->zip_path}");
                }
                $deletedZips++;
            }

            // Delete unpublished images from S3
            $unpublishedItems = $upload->items()
                ->whereNotNull('image_id')
                ->where('is_published', false)
                ->with('image')
                ->get();

            foreach ($unpublishedItems as $item) {
                if ($item->image && $item->image->filename) {
                    if ($dryRun) {
                        $this->line("  Would delete image: {$item->image->filename}");
                    } else {
                        Storage::disk('s3')->delete($item->image->filename);
                        $item->image->delete();
                        $this->line("  Deleted image: {$item->image->filename}");
                    }
                    $deletedImages++;
                }
            }

            // Delete all items and the upload record
            if (!$dryRun) {
                $itemCount = $upload->items()->count();
                $upload->items()->delete();
                $upload->delete();
                $this->line("  Deleted {$itemCount} item records and bulk upload record");
            } else {
                $itemCount = $upload->items()->count();
                $this->line("  Would delete {$itemCount} item records and bulk upload record");
            }
            $deletedRecords++;
        }

        $this->newLine();
        $this->info("Summary:");
        $this->line("  ZIP files " . ($dryRun ? 'to delete' : 'deleted') . ": {$deletedZips}");
        $this->line("  Images " . ($dryRun ? 'to delete' : 'deleted') . ": {$deletedImages}");
        $this->line("  Bulk upload records " . ($dryRun ? 'to delete' : 'deleted') . ": {$deletedRecords}");

        Log::info("CleanupExpiredBulkUploads: Processed {$deletedRecords} expired uploads, {$deletedZips} ZIPs, {$deletedImages} images" . ($dryRun ? ' (dry run)' : ''));

        return Command::SUCCESS;
    }
}
