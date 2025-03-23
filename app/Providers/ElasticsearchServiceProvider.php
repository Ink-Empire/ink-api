<?php

namespace App\Providers;

use App\Scout\ElasticsearchEngine;
use App\Services\ElasticsearchService;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Builder;
use Illuminate\Support\Facades\Log;

class ElasticsearchServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(ElasticsearchService::class, function ($app) {
            return new ElasticsearchService();
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        resolve(EngineManager::class)->extend('elasticsearch', function () {
            return new ElasticsearchEngine(app(ElasticsearchService::class));
        });
    }
}