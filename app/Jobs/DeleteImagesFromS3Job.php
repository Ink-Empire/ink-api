<?php

namespace App\Jobs;

use App\Models\Image;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class DeleteImagesFromS3Job implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    /**
     * @param int $userId For logging
     * @param array $imageData Images to delete: [['image_id' => X, 'filename' => 'path'], ...]
     */
    public function __construct(
        public int   $userId,
        public array $imageData
    )
    {
    }

    public function handle(): void
    {
        Log::info('DeleteImagesFromS3Job: Starting', [
            'user_id' => $this->userId,
            'image_count' => count($this->imageData),
        ]);

        $storage = Storage::disk('s3');
        $deleted = 0;

        foreach ($this->imageData as $image) {
            try {
                if ($image['filename']) {
                    if ($storage->exists($image['filename'])) {
                        $storage->delete($image['filename']);
                    }
                }
                if (isset($image['image_id'])) {
                    Image::where('id', $image['image_id'])->delete();
                }
                $deleted++;
            } catch (\Exception $e) {
                Log::warning('DeleteImagesFromS3Job: Failed to delete image', [
                    'user_id' => $this->userId,
                    'image_id' => $image['image_id'] ?? null,
                    'filename' => $image['filename'] ?? null,
                    'error' => $e->getMessage(),
                    'line' => $e->getLine(),
                    'file' => $e->getFile(),
                ]);
            }
        }

        Log::info('DeleteImagesFromS3Job: Complete', [
            'user_id' => $this->userId,
            'deleted' => $deleted,
            'total' => count($this->imageData),
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('DeleteImagesFromS3Job: Failed', [
            'user_id' => $this->userId,
            'error' => $exception->getMessage(),
        ]);
    }

    public function backoff(): array
    {
        return [10, 30, 60];
    }
}
