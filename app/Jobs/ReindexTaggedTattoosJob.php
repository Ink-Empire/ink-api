<?php

namespace App\Jobs;

use App\Models\Tag;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ReindexTaggedTattoosJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $tagId
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $tag = Tag::find($this->tagId);

        if (!$tag) {
            Log::warning('ReindexTaggedTattoosJob: Tag not found', ['tag_id' => $this->tagId]);
            return;
        }

        $count = 0;

        $tag->tattoos()->chunkById(100, function ($tattoos) use (&$count) {
            foreach ($tattoos as $tattoo) {
                $tattoo->searchable();
                $count++;
            }
        });

        Log::info('ReindexTaggedTattoosJob: Completed', [
            'tag_id' => $this->tagId,
            'tag_name' => $tag->name,
            'tattoos_reindexed' => $count,
        ]);
    }
}
