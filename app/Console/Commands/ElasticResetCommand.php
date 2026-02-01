<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

class ElasticResetCommand extends Command
{
    protected $signature = 'elastic:reset
                            {model : The model class (e.g. Artist, Tattoo)}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Drop, recreate, and reindex an Elasticsearch index (full reset)';

    protected $modelMap = [
        'Artist' => 'App\\Models\\Artist',
        'Tattoo' => 'App\\Models\\Tattoo',
    ];

    public function handle()
    {
        $modelName = $this->argument('model');
        $modelClass = $this->modelMap[$modelName] ?? 'App\\Models\\' . $modelName;

        if (!class_exists($modelClass)) {
            $this->error("Model class {$modelClass} does not exist");
            return Command::FAILURE;
        }

        // Confirm unless --force is used
        if (!$this->option('force')) {
            if (!$this->confirm("This will DELETE all data in the {$modelName} index and rebuild from scratch. Continue?")) {
                $this->info('Operation cancelled.');
                return Command::SUCCESS;
            }
        }

        $this->info("Starting full reset for {$modelName}...");
        $startTime = microtime(true);

        try {
            // Step 1: Drop the index
            $this->info('Step 1/3: Dropping existing index...');
            $dropResult = Artisan::call('elastic:delete-index', [
                'model' => $modelClass,
            ]);
            $this->line(Artisan::output());

            // Step 2: Create the index with mappings
            $this->info('Step 2/3: Creating index with mappings...');
            $createResult = Artisan::call('elastic:create-index-ifnotexists', [
                'model' => $modelClass,
            ]);
            $this->line(Artisan::output());

            // Step 3: Import all data
            $this->info('Step 3/3: Importing data from database...');
            $count = $modelClass::count();
            $this->line("Found {$count} records to index...");
            $modelClass::makeAllSearchable();
            $this->line("Import completed.");

            $duration = round(microtime(true) - $startTime, 2);

            Log::info("Elastic reset completed for {$modelName}", [
                'duration_seconds' => $duration,
            ]);

            $this->newLine();
            $this->info("Reset completed for {$modelName} in {$duration} seconds");

            return Command::SUCCESS;

        } catch (\Exception $e) {
            Log::error("Elastic reset failed for {$modelName}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->error("Reset failed: " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
