<?php

namespace App\Jobs;

use App\Models\Image;
use App\Models\Tattoo;
use App\Services\ElasticService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

class CleanupDeletedUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    /**
     * @param int $userId For logging
     * @param array $tattooImageData Orphaned images from artist's own tattoos: [['image_id' => X, 'filename' => 'path'], ...]
     * @param string|null $profileImageFilename Profile image S3 path
     * @param int|null $profileImageId Profile image DB record ID (already detached from user)
     * @param array $ownTattooIds IDs of artist's own tattoos to remove from ES (DB records already deleted)
     * @param array $clientTattooIds IDs of client-uploaded tattoos to re-index in ES (artist_id nulled)
     */
    public function __construct(
        public int     $userId,
        public array   $tattooImageData,
        public ?string $profileImageFilename,
        public ?int    $profileImageId,
        public array   $ownTattooIds,
        public array   $clientTattooIds
    )
    {
    }

    public function handle(ElasticService $elasticService): void
    {
        Log::info('CleanupDeletedUserJob: Starting cleanup', [
            'user_id' => $this->userId,
            'tattoo_images' => count($this->tattooImageData),
            'has_profile_image' => $this->profileImageFilename !== null,
            'own_tattoos' => count($this->ownTattooIds),
            'client_tattoos' => count($this->clientTattooIds),
        ]);

        $storage = Storage::disk('s3');

        // 1. Delete orphaned tattoo images from S3 + delete Image records
        foreach ($this->tattooImageData as $imageData) {
            try {
                if ($imageData['filename'] && $storage->exists($imageData['filename'])) {
                    $storage->delete($imageData['filename']);
                }
                Image::where('id', $imageData['image_id'])->delete();
            } catch (\Exception $e) {
                Log::warning('CleanupDeletedUserJob: Failed to delete tattoo image', [
                    'user_id' => $this->userId,
                    'image_id' => $imageData['image_id'],
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]);
            }
        }

        // 2. Delete profile image from S3 + delete Image record
        if ($this->profileImageFilename) {
            try {
                if ($storage->exists($this->profileImageFilename)) {
                    $storage->delete($this->profileImageFilename);
                }
            } catch (\Exception $e) {
                Log::warning('CleanupDeletedUserJob: Failed to delete profile image from S3', [
                    'user_id' => $this->userId,
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]);
            }
        }

        if ($this->profileImageId) {
            try {
                Image::where('id', $this->profileImageId)->delete();
            } catch (\Exception $e) {
                Log::warning('CleanupDeletedUserJob: Failed to delete profile image record', [
                    'user_id' => $this->userId,
                    'image_id' => $this->profileImageId,
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]);
            }
        }

        // 3. Remove artist's own tattoos from ES (DB records already deleted)
        if (!empty($this->ownTattooIds)) {
            $tattooIndex = config('elastic.client.tattoos_index', 'tattoos');
            $elasticService->deleteByIds($this->ownTattooIds, $tattooIndex);
        }

        // 4. Re-index client tattoos to reflect nulled artist_id
        if (!empty($this->clientTattooIds)) {
            $elasticService->rebuild($this->clientTattooIds, Tattoo::class);
        }

        // 5. Bust tattoo caches in bulk, chunked to avoid blocking Redis
        $allTattooIds = array_merge($this->ownTattooIds, $this->clientTattooIds);
        if (!empty($allTattooIds)) {
            try {
                $prefix = config('cache.prefix');
                $keys = array_map(fn($id) => "{$prefix}:es:tattoo:{$id}", $allTattooIds);
                foreach (array_chunk($keys, 200) as $chunk) {
                    Redis::del(...$chunk);
                }
            } catch (\Exception $e) {
                Log::warning('CleanupDeletedUserJob: Failed to clear redis of tattoo keys', [
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile()
                ]);
            }
        }

        Log::info('CleanupDeletedUserJob: Cleanup complete', [
            'user_id' => $this->userId,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CleanupDeletedUserJob: Job failed', [
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }
}
