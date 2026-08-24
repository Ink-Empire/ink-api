<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Models\Tattoo;
use App\Services\ElasticService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Reindex an artist and their tattoos after a studio affiliation changes.
 *
 * Both documents carry a copy of the studio the artist works at, so joining a
 * studio, leaving one, or switching the primary one leaves stale shop names in
 * search until this runs.
 */
class ReindexArtistAffiliationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $artistId)
    {
    }

    public function handle(ElasticService $elasticService): void
    {
        $artistResult = $elasticService->rebuild([$this->artistId], Artist::class);

        $tattooIds = Tattoo::where('artist_id', $this->artistId)->pluck('id')->all();
        $tattooResult = ['indexed' => 0];

        if (!empty($tattooIds)) {
            foreach (array_chunk($tattooIds, config('scout.chunk.searchable', 500)) as $chunk) {
                $chunkResult = $elasticService->rebuild($chunk, Tattoo::class);
                $tattooResult['indexed'] += $chunkResult['indexed'] ?? 0;
            }
        }

        Log::info("Reindexed artist after affiliation change", [
            'artist_id' => $this->artistId,
            'artist_indexed' => $artistResult['indexed'] ?? 0,
            'tattoos_indexed' => $tattooResult['indexed'],
        ]);
    }

    public function failed($exception): void
    {
        Log::error("Failed to reindex artist after affiliation change", [
            'artist_id' => $this->artistId,
            'error' => $exception->getMessage(),
        ]);
    }
}
