<?php

namespace App\Jobs;

use App\Models\Artist;
use App\Models\Tattoo;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class IndexTattooJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;

    public function __construct(
        public int $tattooId,
        public bool $reindexArtist = true
    ) {
    }

    public function handle(): void
    {
        $tattoo = Tattoo::with([
            'tags', 'styles', 'images', 'artist', 'studio', 'primary_style', 'primary_image', 'uploader.image',
        ])->find($this->tattooId);

        if (!$tattoo) {
            Log::warning("IndexTattooJob: Tattoo not found", ['tattoo_id' => $this->tattooId]);
            return;
        }

        $tattoo->searchable();
        Cache::forget("es:tattoo:{$this->tattooId}");
        Log::info("IndexTattooJob: Indexed tattoo", ['tattoo_id' => $this->tattooId]);

        if ($tattoo->uploaded_by_user_id) {
            self::bustUserTattooCaches($tattoo->uploaded_by_user_id);
        }

        if ($this->reindexArtist && $tattoo->artist_id) {
            $artist = Artist::find($tattoo->artist_id);
            if ($artist) {
                $artist->searchable();
                self::bustArtistCaches($artist->id, $artist->slug);
            }
        }
    }

    public static function bustArtistCaches(int $artistId, ?string $slug): void
    {
        Cache::forget("es:artist:detail:{$artistId}");
        if ($slug) {
            Cache::forget("es:artist:detail:{$slug}");
        }
        Cache::forget("artist:{$artistId}:dashboard-tattoos");

        $prefix = config('cache.prefix');
        $pattern = "{$prefix}:es:artist:portfolio:{$artistId}:*";
        $cursor = '0';
        do {
            [$cursor, $keys] = Redis::scan($cursor, ['match' => $pattern, 'count' => 100]);
            if (!empty($keys)) {
                Redis::del(...$keys);
            }
        } while ($cursor !== '0');

        if ($slug) {
            $pattern = "{$prefix}:es:artist:portfolio:{$slug}:*";
            $cursor = '0';
            do {
                [$cursor, $keys] = Redis::scan($cursor, ['match' => $pattern, 'count' => 100]);
                if (!empty($keys)) {
                    Redis::del(...$keys);
                }
            } while ($cursor !== '0');
        }
    }

    public static function bustUserTattooCaches(int $userId): void
    {
        $prefix = config('cache.prefix');
        $pattern = "{$prefix}:es:user:{$userId}:tattoos:*";
        $cursor = '0';
        do {
            [$cursor, $keys] = Redis::scan($cursor, ['match' => $pattern, 'count' => 100]);
            if (!empty($keys)) {
                Redis::del(...$keys);
            }
        } while ($cursor !== '0');
    }

    public function backoff(): array
    {
        return [5, 15, 30];
    }
}
