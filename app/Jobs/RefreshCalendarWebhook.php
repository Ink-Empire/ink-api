<?php

namespace App\Jobs;

use App\Models\CalendarConnection;
use App\Services\GoogleCalendarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Refreshes one connection's Google push channel.
 *
 * One job per connection so a retry redoes a single calendar rather than the
 * whole platform, and so one artist's failure cannot stop everyone else's
 * webhooks from being renewed before they expire.
 */
class RefreshCalendarWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 600];

    public int $timeout = 120;

    public function __construct(
        public int $connectionId
    ) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->connectionId))->dontRelease(),
        ];
    }

    public function handle(GoogleCalendarService $googleCalendar): void
    {
        $connection = CalendarConnection::find($this->connectionId);

        if (! $connection || ! $connection->sync_enabled) {
            return;
        }

        // A failed stop leaves the old channel delivering until it expires on
        // its own, which the webhook controller's throttle absorbs. Failing to
        // create the new one is the outcome worth retrying for.
        try {
            $googleCalendar->stopWebhook($connection);
        } catch (\Exception $e) {
            Log::warning("Could not stop old webhook for connection {$connection->id}: ".$e->getMessage());
        }

        $googleCalendar->setupWebhook($connection);

        Log::info("Refreshed webhook for calendar connection {$connection->id}");
    }
}
