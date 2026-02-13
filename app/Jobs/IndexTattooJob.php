<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Models\Tattoo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class IndexTattooJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public int $tattooId,
        public bool $reindexArtist = true
    ) {}

    public function handle(): void
    {
        $tattoo = Tattoo::with([
            'tags', 'styles', 'images', 'artist', 'studio', 'primary_style', 'primary_image',
        ])->find($this->tattooId);

        if (!$tattoo) {
            Log::warning("IndexTattooJob: Tattoo not found", ['tattoo_id' => $this->tattooId]);
            return;
        }

        $tattoo->searchable();
        Log::info("IndexTattooJob: Indexed tattoo", ['tattoo_id' => $this->tattooId]);

        if ($this->reindexArtist && $tattoo->artist_id) {
            Artist::find($tattoo->artist_id)?->searchable();
        }
    }

    public function backoff(): array
    {
        return [5, 15, 30];
    }
}
