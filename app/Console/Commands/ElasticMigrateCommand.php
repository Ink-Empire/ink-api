<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Models\Tattoo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ElasticMigrateCommand extends Command
{
    protected $signature = 'elastic:migrate {--force : Force the operation to run}';
    protected $description = 'Create Elasticsearch indices and import data';

    public function handle()
    {
        $this->info('Starting Elasticsearch migration...');

        try {
            // Create indices if they don't exist
            $this->call('elastic:create-index-ifnotexists', [
                'model' => 'App\\Models\\Tattoo'
            ]);

            $this->call('elastic:create-index-ifnotexists', [
                'model' => 'App\\Models\\Artist'
            ]);

            // Import data to Elasticsearch
            $this->info('Importing Tattoo data...');
            $tattoos = Tattoo::with(['artist', 'studio', 'images', 'primary_style', 'styles'])->get();
            if ($tattoos->count() > 0) {
                $tattoos->searchable();
                $this->info("Imported {$tattoos->count()} tattoos");
            } else {
                $this->warn('No tattoos found to import');
            }

            $this->info('Importing Artist data...');
            $artists = Artist::with(['studio', 'styles', 'primary_image', 'settings'])->get();
            if ($artists->count() > 0) {
                $artists->searchable();
                $this->info("Imported {$artists->count()} artists");
            } else {
                $this->warn('No artists found to import');
            }

            $this->info('Elasticsearch migration completed successfully');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            Log::error('Elasticsearch migration failed', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);
            $this->error('Elasticsearch migration failed: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }
}