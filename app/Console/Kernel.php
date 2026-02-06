<?php

namespace App\Console;

use App\Jobs\RefreshCalendarWebhooks;
use App\Jobs\SyncUserCalendar;
use App\Models\CalendarConnection;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     *
     * @param \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // Refresh calendar webhooks daily (before they expire)
        $schedule->job(new RefreshCalendarWebhooks)
            ->daily()
            ->withoutOverlapping()
            ->onOneServer();

        // Update popularity counts (saved_count) for sorting
        $schedule->command('popularity:update')
            ->dailyAt('12:00')
            ->withoutOverlapping()
            ->onOneServer();

        // Periodic sync for all calendars (backup for webhooks)
        // Syncs calendars that haven't been synced in the last 6 hours
        $schedule->call(function () {
            CalendarConnection::where('sync_enabled', true)
                ->where(function ($q) {
                    $q->whereNull('last_synced_at')
                      ->orWhere('last_synced_at', '<', now()->subHours(6));
                })
                ->each(function ($connection) {
                    SyncUserCalendar::dispatch($connection->id);
                });
        })->hourly()->withoutOverlapping()->onOneServer();
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }

    protected $commands = [
        'App\Console\Commands\CreateIndexIfNotExists',
        'App\Console\Commands\DeleteIndexIfExistsCommand',
        'App\Console\Commands\ElasticMigrateCommand',
        'App\Console\Commands\ElasticRebuildCommand',
        'App\Console\Commands\RebuildElasticItem',
    ];
}
