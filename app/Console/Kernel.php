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
        // Poll inbound email mailbox every 3 minutes
        $schedule->command('email:fetch-inbound')
            ->everyThreeMinutes()
            ->withoutOverlapping()
            ->onOneServer();

        // Expire provisional email-inbound accounts with no login after 14 days
        $schedule->command('users:expire-provisional')
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->onOneServer();

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

        // Refresh demo data dates so they always appear current.
        // everyTwoWeeks() is Laravel 11; on 10 it throws while the schedule is
        // being registered, which stops every task below it from registering at
        // all.
        $schedule->command('demo:refresh-dates --force')
            ->twiceMonthly()
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
        })->name('sync-due-calendars')->hourly()->withoutOverlapping()->onOneServer();

        // Production health checks. Alerts the ops channel when a check changes
        // state, so an index that has quietly stopped updating is noticed
        // without anyone going looking for it.
        // sentryMonitor checks in at the start and end of every run. Without it
        // a dead scheduler is indistinguishable from a healthy system, since
        // every other check here runs on the machine it is checking.
        $schedule->command('ops:health-check')
            ->hourly()
            ->sentryMonitor(checkInMargin: 10, maxRuntime: 5)
            ->withoutOverlapping()
            ->onOneServer()
            ->environments(['production']);

        // A full report every Monday morning, whatever the state. The hourly run
        // stays quiet unless something breaks, so this is the standing proof it
        // is still running, and where warnings that never reach alert severity
        // get seen.
        $schedule->command('ops:health-check --summary')
            ->weeklyOn(1, '9:00')
            ->withoutOverlapping()
            ->onOneServer()
            ->environments(['production']);

        // Google deletes OAuth clients after five months without a token
        // exchange, and calendar sync only talks to Google when someone has a
        // calendar connected. Weekly is far more often than the policy needs;
        // the point of the cadence is that a keepalive which has itself broken
        // shows up in the logs within a week rather than months later.
        $schedule->command('google:keepalive')
            ->weekly()
            ->withoutOverlapping()
            ->onOneServer()
            ->environments(['production']);
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
