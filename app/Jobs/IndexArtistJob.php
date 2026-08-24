<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Scopes\ArtistScope;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IndexArtistJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public int $artistId
    ) {
    }

    public function handle(): void
    {
        // ArtistScope pins lookups to type_id = 2, which would make this a no-op
        // for studio accounts.
        $artist = Artist::withoutGlobalScope(ArtistScope::class)->find($this->artistId);

        if (!$artist) {
            Log::warning("IndexArtistJob: Artist not found", ['artist_id' => $this->artistId]);
            return;
        }

        $artist->searchable();

        // Studio accounts also own a venue row that lives in the studios index.
        $artist->ownedStudio?->searchable();

        IndexTattooJob::bustArtistCaches($artist->id, $artist->slug);

        Log::info("IndexArtistJob: Indexed artist", ['artist_id' => $this->artistId]);
    }

    public function backoff(): array
    {
        return [5, 15, 30];
    }
}
