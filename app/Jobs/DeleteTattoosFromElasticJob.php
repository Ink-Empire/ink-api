<?php

namespace App\Jobs;

use App\Models\Tattoo;
use App\Services\ElasticService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class DeleteTattoosFromElasticJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    /**
     * @param int $userId For logging
     * @param array $deleteFromEsIds Tattoo IDs to delete from ES (DB records already gone)
     * @param array $reindexIds Tattoo IDs to re-index in ES (DB records still exist, artist_id nulled)
     */
    public function __construct(
        public int   $userId,
        public array $deleteFromEsIds,
        public array $reindexIds
    )
    {
    }

    public function handle(ElasticService $elasticService): void
    {
        Log::info('DeleteTattoosFromElasticJob: Starting', [
            'user_id' => $this->userId,
            'delete_count' => count($this->deleteFromEsIds),
            'delete_ids' => $this->deleteFromEsIds,
            'reindex_count' => count($this->reindexIds),
            'reindex_ids' => $this->reindexIds,
        ]);

        if (!empty($this->deleteFromEsIds)) {
            $tattooIndex = config('elastic.client.tattoos_index', 'tattoos');
            $elasticService->deleteByIds($this->deleteFromEsIds, $tattooIndex);
        }

        if (!empty($this->reindexIds)) {
            $elasticService->rebuild($this->reindexIds, Tattoo::class);
        }

        // Bust caches
        $allIds = array_merge($this->deleteFromEsIds, $this->reindexIds);
        if (!empty($allIds)) {
            try {
                $prefix = config('cache.prefix');
                $keys = array_map(fn($id) => "{$prefix}:es:tattoo:{$id}", $allIds);
                foreach (array_chunk($keys, 200) as $chunk) {
                    Redis::del(...$chunk);
                }
            } catch (\Exception $e) {
                Log::warning('DeleteTattoosFromElasticJob: Failed to bust caches', [
                    'user_id' => $this->userId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('DeleteTattoosFromElasticJob: Complete', [
            'user_id' => $this->userId,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('DeleteTattoosFromElasticJob: Failed', [
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }
}
