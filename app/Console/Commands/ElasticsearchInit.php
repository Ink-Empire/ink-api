<?php

namespace App\Console\Commands;

use App\Services\ElasticsearchService;
use Illuminate\Console\Command;

class ElasticsearchInit extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'elasticsearch:init {index? : The name of the index to create} {--model= : The model to index data for}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize Elasticsearch indices and index data';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(protected ElasticsearchService $elasticsearch)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $index = $this->argument('index');
        $modelOption = $this->option('model');
        
        // First create the indices if needed
        if ($index) {
            // Create a specific index
            try {
                if (!$this->elasticsearch->indexExists($index)) {
                    $this->elasticsearch->createIndex($index);
                    $this->info("Index {$index} created successfully.");
                } else {
                    $this->warn("Index {$index} already exists.");
                }
            } catch (\Exception $e) {
                $this->error("Failed to create index {$index}: " . $e->getMessage());
                return 1;
            }
        } else {
            // Create both tattoos and artists indices
            try {
                if (!$this->elasticsearch->indexExists('tattoos')) {
                    $this->elasticsearch->createIndex('tattoos');
                    $this->info("Index 'tattoos' created successfully.");
                } else {
                    $this->warn("Index 'tattoos' already exists.");
                }
            } catch (\Exception $e) {
                $this->error("Failed to create index 'tattoos': " . $e->getMessage());
            }

            try {
                if (!$this->elasticsearch->indexExists('artists')) {
                    $this->elasticsearch->createIndex('artists');
                    $this->info("Index 'artists' created successfully.");
                } else {
                    $this->warn("Index 'artists' already exists.");
                }
            } catch (\Exception $e) {
                $this->error("Failed to create index 'artists': " . $e->getMessage());
            }
        }
        
        // Then index data if a model was specified
        if ($modelOption) {
            $this->indexModel($modelOption, $index);
        }
        
        return 0;
    }
    
    /**
     * Index data for the specified model.
     *
     * @param string $modelName
     * @param string|null $indexName
     * @return void
     */
    protected function indexModel($modelName, $indexName = null)
    {
        $modelClass = "App\\Models\\{$modelName}";
        
        if (!class_exists($modelClass)) {
            $this->error("Model {$modelClass} does not exist.");
            return;
        }
        
        $this->info("Indexing {$modelName} data...");
        
        try {
            $model = new $modelClass();
            $index = $indexName ?: $model->searchableAs();
            
            // Get all records and index them
            $model::query()->orderBy('id')->chunk(100, function ($records) use ($index) {
                $this->info("Indexing " . count($records) . " records...");
                
                $documents = [];
                foreach ($records as $record) {
                    try {
                        $documents[$record->getKey()] = $record->toSearchableArray();
                    } catch (\Exception $e) {
                        $this->error("Error preparing record {$record->getKey()} for indexing: " . $e->getMessage());
                    }
                }
                
                if (!empty($documents)) {
                    $this->elasticsearch->bulkIndex($documents, $index);
                }
            });
            
            $this->info("Successfully indexed {$modelName} data.");
        } catch (\Exception $e) {
            $this->error("Failed to index {$modelName} data: " . $e->getMessage());
        }
    }
}