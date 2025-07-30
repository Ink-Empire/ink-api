<?php

namespace App\Console\Commands;

use App\Models\Tattoo;
use App\Services\TagService;
use Illuminate\Console\Command;

class BackfillTattooTags extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tattoos:backfill-tags
                           {--count= : Limit the number of tattoos to process}
                           {--force : Force regeneration of tags for tattoos that already have tags}
                           {--artist= : Only process tattoos for a specific artist ID}
                           {--skip-recent : Skip tattoos created in the last 24 hours}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backfill AI-generated tags for existing tattoos that have uploaded images';

    /**
     * Create a new command instance.
     */
    public function __construct(private TagService $tagService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $count = $this->option('count') ? (int)$this->option('count') : null;
        $force = $this->option('force');
        $artistId = $this->option('artist') ? (int)$this->option('artist') : null;
        $skipRecent = $this->option('skip-recent');

        $this->info('Starting tattoo tags backfill process...');
        $this->newLine();

        $query = $this->buildQuery($force, $artistId, $skipRecent);

        $totalAvailable = $query->count();

        if ($totalAvailable === 0) {
            $this->warn('No tattoos found matching the criteria.');
            return 0;
        }

        if ($count) {
            $query->limit($count);
            $this->info("Processing $count out of $totalAvailable available tattoos...");
        } else {
            $this->info("Processing all $totalAvailable available tattoos...");
        }

        $tattoos = $query->get();

        if ($tattoos->isEmpty()) {
            $this->warn('No tattoos to process.');
            return 0;
        }

        if ($tattoos->count() > 50 && !$force) {
            if (!$this->confirm("You're about to process {$tattoos->count()} tattoos. This may take a while and consume OpenAI API credits. Continue?")) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        $this->processTagsBackfill($tattoos, $force);

        return 0;
    }

    /**
     * Build the query based on options
     */
    private function buildQuery(bool $force, ?int $artistId, bool $skipRecent)
    {
        $query = Tattoo::with(['images', 'tags', 'artist', 'primary_image'])
            ->where(function ($q) {
                // Include tattoos that have images in the pivot table OR have a primary image
                $q->whereHas('images')
                  ->orWhereNotNull('primary_image_id');
            });

        if ($artistId) {
            $this->info('Looking for artist ID: ' . $artistId);
            $query->where('artist_id', $artistId);
        }

        // skip if already have tags unless force is used
        if (!$force) {
            $query->whereDoesntHave('tags');
        }

        if ($skipRecent) {
            $query->where('created_at', '<', now()->subDay());
        }

        $query->orderBy('created_at', 'asc');

        return $query;
    }

    /**
     * Process the backfill for the given tattoos
     */
    private function processTagsBackfill($tattoos, bool $force): void
    {
        $progressBar = $this->output->createProgressBar($tattoos->count());
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
        $progressBar->setMessage('Starting...');
        $progressBar->start();

        $stats = [
            'processed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'total_tags' => 0
        ];

        foreach ($tattoos as $tattoo) {
            $progressBar->setMessage("Processing tattoo ID: {$tattoo->id}");

            try {
                // Skip if tattoo has no images (check both pivot table and primary image)
                $hasImages = $tattoo->images->count() > 0 || ($tattoo->primary_image_id && $tattoo->primary_image);
                if (!$hasImages) {
                    $stats['skipped']++;
                    $progressBar->advance();
                    continue;
                }

                // Skip if tattoo already has tags and force is not used
                if (!$force && $tattoo->tags->count() > 0) {
                    $stats['skipped']++;
                    $progressBar->advance();
                    continue;
                }

                // Generate tags
                $tags = $force
                    ? $this->tagService->regenerateTagsForTattoo($tattoo)
                    : $this->tagService->generateTagsForTattoo($tattoo);

                if (count($tags) > 0) {
                    $stats['processed']++;
                    $stats['total_tags'] += count($tags);

                    // Log success for debugging
                    \Log::info("Backfill: Generated tags for tattoo", [
                        'tattoo_id' => $tattoo->id,
                        'artist_id' => $tattoo->artist_id,
                        'tags_count' => count($tags),
                        'tags' => collect($tags)->pluck('tag')->toArray()
                    ]);
                } else {
                    $stats['failed']++;

                    \Log::warning("Backfill: No tags generated for tattoo", [
                        'tattoo_id' => $tattoo->id,
                        'artist_id' => $tattoo->artist_id,
                        'pivot_images_count' => $tattoo->images->count(),
                        'has_primary_image' => !empty($tattoo->primary_image_id)
                    ]);
                }

                // Add delay to respect OpenAI rate limits
                usleep(500000); // 0.5 seconds between requests

            } catch (\Exception $e) {
                $stats['failed']++;

                \Log::error("Backfill: Failed to process tattoo", [
                    'tattoo_id' => $tattoo->id,
                    'artist_id' => $tattoo->artist_id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->setMessage('Complete!');
        $progressBar->finish();
        $this->newLine(2);

        // Display results
        $this->displayResults($stats);
    }

    /**
     * Display the processing results
     */
    private function displayResults(array $stats): void
    {
        $this->info('🎉 Backfill process completed!');
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Successfully Processed', $stats['processed']],
                ['Failed', $stats['failed']],
                ['Skipped', $stats['skipped']],
                ['Total Tags Generated', $stats['total_tags']],
            ]
        );

        if ($stats['processed'] > 0) {
            $avgTags = round($stats['total_tags'] / $stats['processed'], 2);
            $this->info("📊 Average tags per tattoo: {$avgTags}");
        }

        if ($stats['failed'] > 0) {
            $this->warn("⚠️  {$stats['failed']} tattoos failed to process. Check the logs for details.");
        }

        if ($stats['skipped'] > 0) {
            $this->info("⏭️  {$stats['skipped']} tattoos were skipped (already have tags or no images).");
        }

        $this->newLine();
        $this->info('💡 Tip: Use --force to regenerate tags for tattoos that already have them.');
        $this->info('💡 Tip: Use --artist=ID to process only one artist\'s tattoos.');
        $this->info('💡 Tip: Check storage/logs/laravel.log for detailed processing information.');
    }
}
