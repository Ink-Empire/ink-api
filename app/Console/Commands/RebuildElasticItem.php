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

        $this->info("Rebuilding $type with ID {$this->argument('id')}...");

        $itemToRebuild->searchable();
    }
}
