<?php

namespace App\Jobs;

use App\Models\Artist;
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
        $artist = Artist::find($this->artistId);

        if (!$artist) {
            Log::warning("IndexArtistJob: Artist not found", ['artist_id' => $this->artistId]);
            return;
        }

        $artist->searchable();
        IndexTattooJob::bustArtistCaches($artist->id, $artist->slug);

        Log::info("IndexArtistJob: Indexed artist", ['artist_id' => $this->artistId]);
    }

    public function backoff(): array
    {
        return [5, 15, 30];
    }
}
