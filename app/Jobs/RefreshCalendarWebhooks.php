<?php

namespace App\Jobs;

use App\Models\CalendarConnection;
use App\Services\GoogleCalendarService;
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

    public function handle(GoogleCalendarService $googleCalendar): void
    {
        // Find connections with webhooks expiring in the next 24 hours
        $connections = CalendarConnection::where('sync_enabled', true)
            ->whereNotNull('webhook_expires_at')
            ->where('webhook_expires_at', '<', now()->addDay())
            ->get();

        Log::info("Refreshing webhooks for {$connections->count()} calendar connections");

        foreach ($connections as $connection) {
            try {
                // Stop old webhook
                $googleCalendar->stopWebhook($connection);

                // Create new webhook
                $googleCalendar->setupWebhook($connection);

                Log::info("Refreshed webhook for calendar connection {$connection->id}");
            } catch (\Exception $e) {
                Log::error("Failed to refresh webhook for connection {$connection->id}: " . $e->getMessage());
            }
        }
    }
}
