<?php

namespace App\Jobs;

use App\Models\Tattoo;
use App\Services\TagService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateAiTagsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120; // 2 minutes max for OpenAI calls

    public function __construct(
        public int $tattooId,
        public array $userSelectedTagIds = []
    ) {}

    public function handle(TagService $tagService): void
    {
        Log::info("GenerateAiTagsJob: Starting AI tag generation", [
            'tattoo_id' => $this->tattooId
        ]);

        $tattoo = Tattoo::with(['images', 'tags', 'artist'])->find($this->tattooId);

        if (!$tattoo) {
            Log::warning("GenerateAiTagsJob: Tattoo not found", [
                'tattoo_id' => $this->tattooId
            ]);
            return;
        }

        if ($tattoo->images->count() === 0) {
            Log::warning("GenerateAiTagsJob: No images to analyze", [
                'tattoo_id' => $this->tattooId
            ]);
            return;
        }

        try {
            // Generate AI tags
            $allAiTags = $tagService->generateTagsForTattoo($tattoo);

            Log::info("GenerateAiTagsJob: AI tags generated", [
                'tattoo_id' => $this->tattooId,
                'total_ai_tags' => count($allAiTags),
                'tags' => array_map(fn($t) => $t->name ?? $t, $allAiTags)
            ]);

            // Re-index the tattoo and artist after tags are added
            IndexTattooJob::dispatch($tattoo->id);

            Log::info("GenerateAiTagsJob: Completed successfully", [
                'tattoo_id' => $this->tattooId
            ]);

        } catch (\Exception $e) {
            Log::error("GenerateAiTagsJob: Failed to generate tags", [
                'tattoo_id' => $this->tattooId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e; // Re-throw to trigger retry
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("GenerateAiTagsJob: Job failed permanently", [
            'tattoo_id' => $this->tattooId,
            'error' => $exception->getMessage()
        ]);
    }

    public function backoff(): array
    {
        return [10, 30, 60]; // Retry after 10s, 30s, 60s
    }
}
