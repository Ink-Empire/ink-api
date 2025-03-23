<?php

namespace App\Console\Commands;

use App\Jobs\ElasticRebuildJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ElasticRebuildCommand extends Command
{
    protected $signature = 'elastic:rebuild {model : The model class to rebuild (e.g. App\\Models\\Tattoo)}';
    protected $description = 'Rebuild Elasticsearch index for a specific model';

    public function handle()
    {
        $modelClass = $this->argument('model');
        
        $this->info("Starting rebuild for $modelClass...");

        try {
            if (!class_exists($modelClass)) {
                $this->error("Model class $modelClass does not exist");
                return Command::FAILURE;
            }

            $model = new $modelClass();
            
            // Get all model IDs for rebuild
            $ids = $model->newQuery()->pluck('id')->toArray();
            
            if (empty($ids)) {
                $this->warn("No records found for $modelClass");
                return Command::SUCCESS;
            }
            
            $this->info("Found " . count($ids) . " records to rebuild");
            
            // Use the ElasticRebuildJob to process the rebuild
            ElasticRebuildJob::dispatch($model, $ids);
            
            $this->info("Rebuild job queued successfully for $modelClass");
            return Command::SUCCESS;
            
        } catch (\Exception $e) {
            Log::error("Elasticsearch rebuild failed for $modelClass", [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);
            $this->error("Elasticsearch rebuild failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}