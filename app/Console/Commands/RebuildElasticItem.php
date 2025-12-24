<?php

namespace App\Console\Commands;

use App\Services\ElasticService;
use App\Util\StringToModel;
use Illuminate\Console\Command;
use Larelastic\Elastic\Facades\Elastic;

class RebuildElasticItem extends Command
{
    const VALID_TYPES = [
        'artist',
        'tattoo',
    ];

    //add two args for type and id
    protected $signature = 'elastic:rebuild-item {type} {id}';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Rebuild an individual item';


    public function handle()
    {
        $type = strtolower($this->argument('type'));

        if(!in_array($type, self::VALID_TYPES)) {
            $this->error('Invalid type. Valid types are: ' . implode(', ', self::VALID_TYPES));
            return;
        }

        $model = StringToModel::convert($type);
        $itemToRebuild = $model::find($this->argument('id'));

        if (!$itemToRebuild) {
            $this->error("$type with ID {$this->argument('id')} not found.");
            return;
        }

        $this->info("Rebuilding $type with ID {$this->argument('id')}...");

        try {
            // Load all relationships before indexing
            if ($type === 'tattoo') {
                $itemToRebuild->load(['tags', 'styles', 'images', 'artist', 'studio', 'primary_style']);
            } elseif ($type === 'artist') {
                $itemToRebuild->load(['tattoos', 'styles', 'studio', 'settings']);
            }

            $itemToRebuild->searchable();
            $this->info("Successfully reindexed $type {$this->argument('id')}");
        } catch (\Exception $e) {
            $this->error("Error rebuilding item: {$e->getMessage()}");
            \Log::error("Error rebuilding item: {$e->getMessage()}");
        }
    }
}
