<?php

namespace App\Console\Commands;

use App\Models\Image;
use Illuminate\Console\Command;

class MigrateImagesToImgix extends Command
{
    protected $signature = 'images:migrate-to-imgix
                            {--dry-run : Show what would be changed without making changes}
                            {--batch-size=500 : Number of images to process per batch}';

    protected $description = 'Update image URIs to use the Imgix CDN domain instead of S3/CloudFront URLs';

    public function handle(): int
    {
        $imgixUrl = rtrim(config('filesystems.imgix.url'), '/');
        $batchSize = (int) $this->option('batch-size');
        $dryRun = $this->option('dry-run');

        if (!config('filesystems.imgix.enabled')) {
            $this->error('IMGIX_ENABLED is not set to true. Set it before running this command.');
            return 1;
        }

        // Find all non-Imgix, non-gravatar images
        $total = Image::where('uri', 'not like', '%imgix.net%')
            ->where('uri', 'not like', '%gravatar.com%')
            ->count();

        $this->info("Migrating {$total} image URIs to: {$imgixUrl}");

        if ($dryRun) {
            $this->warn('DRY RUN - no changes will be made.');
        }

        if ($total === 0) {
            $this->info('Nothing to do.');
            return 0;
        }

        $updated = 0;
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Image::where('uri', 'not like', '%imgix.net%')
            ->where('uri', 'not like', '%gravatar.com%')
            ->chunkById($batchSize, function ($images) use ($imgixUrl, $dryRun, &$updated, $bar) {
                foreach ($images as $image) {
                    if (!$dryRun) {
                        // Extract just the filename/path from the existing URL
                        $parsed = parse_url($image->uri);
                        $path = ltrim($parsed['path'] ?? '', '/');

                        if ($path) {
                            $newUri = $imgixUrl . '/' . $path;
                            $image->getConnection()->table('images')
                                ->where('id', $image->id)
                                ->update(['uri' => $newUri]);
                        }
                    }
                    $updated++;
                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine();
        $this->info("Updated {$updated} image URIs.");

        return 0;
    }
}
