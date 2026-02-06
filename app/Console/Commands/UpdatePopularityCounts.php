<?php

namespace App\Console\Commands;

use App\Models\Studio;
use App\Models\Tattoo;
use App\Models\Artist;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UpdatePopularityCounts extends Command
{
    protected $signature = 'popularity:update
                            {--dry-run : Show counts without updating}
                            {--skip-reindex : Skip Elasticsearch reindexing}';

    protected $description = 'Update saved_count for tattoos, artists, and studios based on favorites';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $skipReindex = $this->option('skip-reindex');

        if ($dryRun) {
            $this->info('DRY RUN - No updates will be made');
        }

        $this->updateTattooCounts($dryRun, $skipReindex);
        $this->updateArtistCounts($dryRun, $skipReindex);
        $this->updateStudioCounts($dryRun, $skipReindex);

        $this->newLine();
        $this->info('Popularity counts update complete!');

        return Command::SUCCESS;
    }

    private function updateTattooCounts(bool $dryRun, bool $skipReindex): void
    {
        $this->info('Updating tattoo saved counts...');

        $counts = DB::table('users_tattoos')
            ->select('tattoo_id', DB::raw('COUNT(*) as count'))
            ->groupBy('tattoo_id')
            ->pluck('count', 'tattoo_id')
            ->toArray();

        $this->line("  Found " . count($counts) . " tattoos with saves");

        if ($dryRun) {
            $sample = array_slice($counts, 0, 5, true);
            foreach ($sample as $id => $count) {
                $this->line("  Tattoo #{$id}: {$count} saves");
            }
            if (count($counts) > 5) {
                $this->line("  ... and " . (count($counts) - 5) . " more");
            }
            return;
        }

        // Reset all counts to 0 first
        Tattoo::query()->update(['saved_count' => 0]);

        // Update counts for tattoos that have saves
        $updated = 0;
        $reindexIds = [];

        foreach ($counts as $tattooId => $count) {
            Tattoo::where('id', $tattooId)->update(['saved_count' => $count]);
            $reindexIds[] = $tattooId;
            $updated++;
        }

        $this->line("  Updated {$updated} tattoos with save counts");
        Log::info("UpdatePopularityCounts: Updated {$updated} tattoo saved_counts");

        if (!$skipReindex && !empty($reindexIds)) {
            $this->reindexTattoos($reindexIds);
        }
    }

    private function updateArtistCounts(bool $dryRun, bool $skipReindex): void
    {
        $this->info('Updating artist saved counts...');

        $counts = DB::table('users_artists')
            ->select('artist_id', DB::raw('COUNT(*) as count'))
            ->groupBy('artist_id')
            ->pluck('count', 'artist_id')
            ->toArray();

        $this->line("  Found " . count($counts) . " artists with saves");

        if ($dryRun) {
            $sample = array_slice($counts, 0, 5, true);
            foreach ($sample as $id => $count) {
                $this->line("  Artist #{$id}: {$count} saves");
            }
            if (count($counts) > 5) {
                $this->line("  ... and " . (count($counts) - 5) . " more");
            }
            return;
        }

        // Reset all artist counts to 0 first (only artists, type_id = 2)
        User::where('type_id', 2)->update(['saved_count' => 0]);

        // Update counts for artists that have saves
        $updated = 0;
        $reindexIds = [];

        foreach ($counts as $artistId => $count) {
            User::where('id', $artistId)->update(['saved_count' => $count]);
            $reindexIds[] = $artistId;
            $updated++;
        }

        $this->line("  Updated {$updated} artists with save counts");
        Log::info("UpdatePopularityCounts: Updated {$updated} artist saved_counts");

        if (!$skipReindex && !empty($reindexIds)) {
            $this->reindexArtists($reindexIds);
        }
    }

    private function updateStudioCounts(bool $dryRun, bool $skipReindex): void
    {
        $this->info('Updating studio saved counts...');

        $counts = DB::table('users_studios')
            ->select('studio_id', DB::raw('COUNT(*) as count'))
            ->groupBy('studio_id')
            ->pluck('count', 'studio_id')
            ->toArray();

        $this->line("  Found " . count($counts) . " studios with saves");

        if ($dryRun) {
            $sample = array_slice($counts, 0, 5, true);
            foreach ($sample as $id => $count) {
                $this->line("  Studio #{$id}: {$count} saves");
            }
            if (count($counts) > 5) {
                $this->line("  ... and " . (count($counts) - 5) . " more");
            }
            return;
        }

        // Reset all counts to 0 first
        Studio::query()->update(['saved_count' => 0]);

        // Update counts for studios that have saves
        $updated = 0;
        $reindexIds = [];

        foreach ($counts as $studioId => $count) {
            Studio::where('id', $studioId)->update(['saved_count' => $count]);
            $reindexIds[] = $studioId;
            $updated++;
        }

        $this->line("  Updated {$updated} studios with save counts");
        Log::info("UpdatePopularityCounts: Updated {$updated} studio saved_counts");

        if (!$skipReindex && !empty($reindexIds)) {
            $this->reindexStudios($reindexIds);
        }
    }

    private function reindexTattoos(array $ids): void
    {
        $this->line("  Reindexing " . count($ids) . " tattoos in Elasticsearch...");

        $chunks = array_chunk($ids, 100);
        foreach ($chunks as $chunk) {
            $tattoos = Tattoo::with(['artist', 'studio', 'styles', 'images', 'tags', 'subject'])
                ->whereIn('id', $chunk)
                ->get();

            foreach ($tattoos as $tattoo) {
                $tattoo->searchable();
            }
        }
    }

    private function reindexArtists(array $ids): void
    {
        $this->line("  Reindexing " . count($ids) . " artists in Elasticsearch...");

        $chunks = array_chunk($ids, 100);
        foreach ($chunks as $chunk) {
            $artists = Artist::with(['styles', 'image', 'settings', 'socialMediaLinks'])
                ->whereIn('id', $chunk)
                ->get();

            foreach ($artists as $artist) {
                $artist->searchable();
            }
        }
    }

    private function reindexStudios(array $ids): void
    {
        $this->line("  Reindexing " . count($ids) . " studios in Elasticsearch...");

        $chunks = array_chunk($ids, 100);
        foreach ($chunks as $chunk) {
            $studios = Studio::with(['styles', 'image'])
                ->whereIn('id', $chunk)
                ->get();

            foreach ($studios as $studio) {
                $studio->searchable();
            }
        }
    }
}
