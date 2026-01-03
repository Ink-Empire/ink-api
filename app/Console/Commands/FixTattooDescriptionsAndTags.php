<?php

namespace App\Console\Commands;

use App\Models\Tag;
use App\Models\Tattoo;
use App\Services\TagService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FixTattooDescriptionsAndTags extends Command
{
    protected $signature = 'tattoos:fix-descriptions-tags
                           {--count= : Limit the number of tattoos to process}
                           {--artist= : Only process tattoos for a specific artist ID}
                           {--tattoo= : Process a single tattoo by ID}
                           {--dry-run : Preview changes without saving}
                           {--skip-description : Only fix tags, do not update descriptions}
                           {--skip-tags : Only fix descriptions, do not update tags}
                           {--force : Process all tattoos, even those with existing descriptions/tags}
                           {--create-tags : Create new approved tags from unmatched AI suggestions}';

    protected $description = 'Analyze tattoo images with AI to fix mismatched descriptions and tags';

    public function __construct(private TagService $tagService)
    {
        parent::__construct();
    }

    public function handle()
    {
        $count = $this->option('count') ? (int)$this->option('count') : null;
        $artistId = $this->option('artist') ? (int)$this->option('artist') : null;
        $tattooId = $this->option('tattoo') ? (int)$this->option('tattoo') : null;
        $dryRun = $this->option('dry-run');
        $skipDescription = $this->option('skip-description');
        $skipTags = $this->option('skip-tags');
        $force = $this->option('force');
        $createTags = $this->option('create-tags');

        if ($dryRun) {
            $this->warn('🔍 DRY RUN MODE - No changes will be saved');
            $this->newLine();
        }

        if ($createTags) {
            $this->info('🏷️  CREATE TAGS MODE - New tags will be created from unmatched AI suggestions');
            $this->newLine();
        }

        $this->info('Starting tattoo description and tag fix process...');
        $this->newLine();

        // Load all existing approved tags for matching
        $existingTags = Tag::approved()->pluck('name')->toArray();
        $this->info("Loaded " . count($existingTags) . " existing approved tags for matching");
        $this->newLine();

        // Build query
        $query = $this->buildQuery($tattooId, $artistId, $force);
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

        // Confirm if processing many tattoos
        if ($tattoos->count() > 20 && !$dryRun) {
            if (!$this->confirm("You're about to process {$tattoos->count()} tattoos. This will consume OpenAI API credits. Continue?")) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        $this->processTattoos($tattoos, $existingTags, $dryRun, $skipDescription, $skipTags, $createTags);

        return 0;
    }

    private function buildQuery(?int $tattooId, ?int $artistId, bool $force)
    {
        $query = Tattoo::with(['images', 'tags', 'artist', 'primary_image'])
            ->where(function ($q) {
                $q->whereHas('images')
                  ->orWhereNotNull('primary_image_id');
            });

        if ($tattooId) {
            $query->where('id', $tattooId);
            return $query;
        }

        if ($artistId) {
            $this->info('Filtering by artist ID: ' . $artistId);
            $query->where('artist_id', $artistId);
        }

        // Unless force is used, only process tattoos that have tags (to fix mismatches)
        // or that have no description
        if (!$force) {
            $query->where(function ($q) {
                $q->whereHas('tags') // Has tags that might be wrong
                  ->orWhereNull('description')
                  ->orWhere('description', '');
            });
        }

        $query->orderBy('id', 'asc');

        return $query;
    }

    private function processTattoos($tattoos, array $existingTags, bool $dryRun, bool $skipDescription, bool $skipTags, bool $createTags): void
    {
        $progressBar = $this->output->createProgressBar($tattoos->count());
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
        $progressBar->setMessage('Starting...');
        $progressBar->start();

        $stats = [
            'processed' => 0,
            'descriptions_updated' => 0,
            'tags_updated' => 0,
            'tags_created' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        $createdTagNames = [];
        $changes = [];

        foreach ($tattoos as $tattoo) {
            $progressBar->setMessage("Processing tattoo ID: {$tattoo->id}");

            try {
                // Get the primary image URL
                $imageUrl = $this->getTattooImageUrl($tattoo);

                if (!$imageUrl) {
                    $stats['skipped']++;
                    $progressBar->advance();
                    continue;
                }

                // Analyze the image
                $analysis = $this->tagService->analyzeTattooForDescriptionAndTags($imageUrl, $existingTags);

                if (!$analysis['description'] && empty($analysis['matched_tags'])) {
                    $stats['failed']++;
                    $progressBar->advance();
                    continue;
                }

                $change = [
                    'tattoo_id' => $tattoo->id,
                    'artist_id' => $tattoo->artist_id,
                    'image_url' => $imageUrl,
                ];

                // Update description
                if (!$skipDescription && $analysis['description']) {
                    $change['old_description'] = $tattoo->description;
                    $change['new_description'] = $analysis['description'];

                    if (!$dryRun) {
                        $tattoo->description = $analysis['description'];
                        $tattoo->save();
                        $stats['descriptions_updated']++;
                    }
                }

                // Update tags
                if (!$skipTags && !empty($analysis['suggested_tags'])) {
                    $change['old_tags'] = $tattoo->tags->pluck('name')->toArray();
                    $change['suggested_tags'] = $analysis['suggested_tags'];

                    // Start with matched tags
                    $allTags = collect($analysis['matched_tags']);
                    $matchedNames = $allTags->pluck('name')->map(fn($n) => strtolower($n))->toArray();

                    // Find unmatched suggestions
                    $unmatchedSuggestions = array_filter($analysis['suggested_tags'], function($tag) use ($matchedNames) {
                        return !in_array(strtolower($tag), $matchedNames);
                    });

                    $change['unmatched_tags'] = array_values($unmatchedSuggestions);
                    $change['created_tags'] = [];

                    // Create new tags if --create-tags is enabled
                    if ($createTags && !empty($unmatchedSuggestions)) {
                        foreach ($unmatchedSuggestions as $tagName) {
                            $tagName = strtolower(trim($tagName));

                            // Skip generic words
                            $skipWords = ['design', 'color', 'pattern', 'art', 'style', 'piece', 'work', 'image'];
                            if (in_array($tagName, $skipWords)) {
                                continue;
                            }

                            // Check if tag already exists in database
                            $existingTag = Tag::where('name', $tagName)
                                ->orWhere('slug', \Illuminate\Support\Str::slug($tagName))
                                ->first();

                            if ($existingTag) {
                                $allTags->push($existingTag);
                                continue;
                            }

                            // Skip if already created in this run
                            if (in_array($tagName, $createdTagNames)) {
                                continue;
                            }

                            if (!$dryRun) {
                                // Create the new tag as approved (AI-generated)
                                $newTag = Tag::create([
                                    'name' => $tagName,
                                    'slug' => \Illuminate\Support\Str::slug($tagName),
                                    'is_pending' => false,
                                    'is_ai_generated' => true,
                                ]);

                                $allTags->push($newTag);
                                $createdTagNames[] = $tagName;
                                $stats['tags_created']++;
                                $change['created_tags'][] = $tagName;

                                Log::info("Created new tag from AI suggestion", [
                                    'tag_id' => $newTag->id,
                                    'name' => $tagName,
                                    'tattoo_id' => $tattoo->id
                                ]);
                            } else {
                                $change['created_tags'][] = $tagName . ' (would create)';
                            }
                        }
                    }

                    $change['new_tags'] = $allTags->pluck('name')->toArray();

                    if (!$dryRun && $allTags->isNotEmpty()) {
                        $tagIds = $allTags->pluck('id')->toArray();

                        Log::info("Syncing tags to tattoo", [
                            'tattoo_id' => $tattoo->id,
                            'tag_ids' => $tagIds,
                            'tag_names' => $allTags->pluck('name')->toArray()
                        ]);

                        $tattoo->tags()->sync($tagIds);
                        $tattoo->refresh();

                        Log::info("Tags synced successfully", [
                            'tattoo_id' => $tattoo->id,
                            'tags_after_sync' => $tattoo->tags->pluck('name')->toArray()
                        ]);

                        $stats['tags_updated']++;
                    } elseif (!$dryRun && $allTags->isEmpty()) {
                        Log::warning("No tags to sync for tattoo", [
                            'tattoo_id' => $tattoo->id,
                            'matched_tags_count' => count($analysis['matched_tags']),
                            'suggested_tags' => $analysis['suggested_tags']
                        ]);
                    }
                }

                $changes[] = $change;
                $stats['processed']++;

                // Rate limiting - 500ms between requests
                usleep(500000);

            } catch (\Exception $e) {
                $stats['failed']++;
                Log::error("Failed to fix tattoo", [
                    'tattoo_id' => $tattoo->id,
                    'error' => $e->getMessage()
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->setMessage('Complete!');
        $progressBar->finish();
        $this->newLine(2);

        // Display results
        $this->displayResults($stats, $changes, $dryRun);
    }

    private function getTattooImageUrl(Tattoo $tattoo): ?string
    {
        // Prefer primary image
        if ($tattoo->primary_image && $tattoo->primary_image->uri) {
            return $tattoo->primary_image->uri;
        }

        // Fall back to first image in pivot
        $firstImage = $tattoo->images->first();
        if ($firstImage && $firstImage->uri) {
            return $firstImage->uri;
        }

        return null;
    }

    private function displayResults(array $stats, array $changes, bool $dryRun): void
    {
        if ($dryRun) {
            $this->warn('🔍 DRY RUN RESULTS - No changes were saved');
        } else {
            $this->info('🎉 Fix process completed!');
        }
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Processed', $stats['processed']],
                ['Descriptions Updated', $stats['descriptions_updated']],
                ['Tags Updated', $stats['tags_updated']],
                ['New Tags Created', $stats['tags_created']],
                ['Failed', $stats['failed']],
                ['Skipped (no image)', $stats['skipped']],
            ]
        );

        // Show detailed changes for first 10 items
        if (!empty($changes)) {
            $this->newLine();
            $this->info('📋 Changes preview (first 10):');
            $this->newLine();

            foreach (array_slice($changes, 0, 10) as $change) {
                $this->line("━━━ Tattoo ID: {$change['tattoo_id']} ━━━");

                if (isset($change['old_description'])) {
                    $this->line("  <fg=red>Old desc:</> " . substr($change['old_description'] ?? '(none)', 0, 80));
                    $this->line("  <fg=green>New desc:</> " . substr($change['new_description'], 0, 80));
                }

                if (isset($change['old_tags'])) {
                    $this->line("  <fg=red>Old tags:</> " . implode(', ', $change['old_tags']) ?: '(none)');
                    $this->line("  <fg=yellow>AI suggested:</> " . implode(', ', $change['suggested_tags'] ?? []));

                    if (!empty($change['unmatched_tags'])) {
                        $this->line("  <fg=magenta>Unmatched:</> " . implode(', ', $change['unmatched_tags']));
                    }

                    if (!empty($change['created_tags'])) {
                        $this->line("  <fg=cyan>Created:</> " . implode(', ', $change['created_tags']));
                    }

                    $this->line("  <fg=green>Final tags:</> " . implode(', ', $change['new_tags'] ?? []));
                }

                $this->newLine();
            }
        }

        if ($stats['failed'] > 0) {
            $this->warn("⚠️  {$stats['failed']} tattoos failed to process. Check storage/logs/laravel.log for details.");
        }

        if ($stats['tags_created'] > 0) {
            $this->info("🏷️  {$stats['tags_created']} new tags were created and marked as AI-generated.");
        }

        $this->newLine();
        $this->info('💡 Tips:');
        $this->info('   --dry-run           Preview changes without saving');
        $this->info('   --tattoo=ID         Process a single tattoo');
        $this->info('   --artist=ID         Process only one artist\'s tattoos');
        $this->info('   --skip-description  Only update tags');
        $this->info('   --skip-tags         Only update descriptions');
        $this->info('   --create-tags       Create new tags from unmatched AI suggestions');
        $this->info('   --force             Process all tattoos (not just those with issues)');
    }
}
