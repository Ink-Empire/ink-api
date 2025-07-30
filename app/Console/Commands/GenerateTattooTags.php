<?php

namespace App\Console\Commands;

use App\Models\Tattoo;
use App\Services\TagService;
use Illuminate\Console\Command;

class GenerateTattooTags extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tattoos:generate-tags
                           {tattoo : The tattoo ID to generate tags for}
                           {--regenerate : Regenerate tags even if they already exist}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate AI tags for a specific tattoo using OpenAI';

    /**
     * Create a new command instance.
     */
    public function __construct(private TagService $tattooTagService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $tattooId = (int) $this->argument('tattoo');
        $regenerate = $this->option('regenerate');

        $this->processSingleTattoo($tattooId, $regenerate);

        return 0;
    }

    /**
     * Process a single tattoo
     */
    private function processSingleTattoo(int $tattooId, bool $regenerate): void
    {
        $tattoo = Tattoo::with(['images', 'tags'])->find($tattooId);

        if (!$tattoo) {
            $this->error("Tattoo with ID {$tattooId} not found.");
            return;
        }

        $this->info("Processing tattoo ID: {$tattoo->id}");

        if (!$regenerate && $tattoo->tags->count() > 0) {
            $this->warn("Tattoo already has tags. Use --regenerate to overwrite.");
            return;
        }

        if ($tattoo->images->count() === 0) {
            $this->warn("Tattoo has no images to analyze.");
            return;
        }

        $tags = $regenerate
            ? $this->tattooTagService->regenerateTagsForTattoo($tattoo)
            : $this->tattooTagService->generateTagsForTattoo($tattoo);

        if (count($tags) > 0) {
            $tagNames = collect($tags)->pluck('tag')->implode(', ');
            $this->info("Generated tags: {$tagNames}");
        } else {
            $this->warn("No tags were generated.");
        }
    }

}
