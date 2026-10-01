<?php

namespace App\Providers;

use App\Filesystem\GuardedFilesystemManager;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Scout\Console\ImportCommand;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        // Replaces the framework's manager so every S3 client is built with the
        // cross-environment delete guard attached. See docs/s3-environments.md.
        $this->app->singleton('filesystem', function ($app) {
            return new GuardedFilesystemManager($app);
        });

        if ($this->app->environment('local')) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }

        // Register Scout commands for HTTP requests (needed for admin reindex)
        $this->commands([
            ImportCommand::class,
        ]);
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        Password::defaults(function () {
            return Password::min(8)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols();
        });
    }
}