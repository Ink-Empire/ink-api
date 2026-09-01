<?php

namespace App\Jobs;

use App\Models\CalendarConnection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RefreshCalendarWebhooks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [60, 300, 600];

    public function handle(): void
    {
        // Fans out rather than renewing every channel inline. Each renewal is
        // two or three sequential Google calls, so one job walking hundreds of
        // connections runs for minutes and a retry near the end would redo the
        // ones it had already renewed.
        $dispatched = 0;

        CalendarConnection::where('sync_enabled', true)
            ->whereNotNull('webhook_expires_at')
            ->where('webhook_expires_at', '<', now()->addDay())
            ->chunkById(200, function ($connections) use (&$dispatched) {
                foreach ($connections as $connection) {
                    RefreshCalendarWebhook::dispatch($connection->id);
                    $dispatched++;
                }
            });

        Log::info("Queued webhook refresh for {$dispatched} calendar connections");
    }
}
