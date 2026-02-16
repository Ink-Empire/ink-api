<?php

namespace App\Jobs;

use App\Models\Artist;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class IndexArtistJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public int $artistId
    ) {}

    public function handle(): void
    {
        $artist = Artist::find($this->artistId);

        if (!$artist) {
            Log::warning("IndexArtistJob: Artist not found", ['artist_id' => $this->artistId]);
            return;
        }

        $artist->searchable();

        Cache::forget("es:artist:detail:{$artist->slug}");
        Cache::forget("es:artist:detail:{$artist->id}");

        Log::info("IndexArtistJob: Indexed artist", ['artist_id' => $this->artistId]);
    }

    public function backoff(): array
    {
        return [5, 15, 30];
    }
}
