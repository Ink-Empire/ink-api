<?php

namespace App\Jobs;

use App\Models\CalendarConnection;
use App\Services\GoogleCalendarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class SyncUserCalendar implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 60, 120];
    public int $timeout = 300;

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

        if (!$connection) {
            Log::warning("Calendar connection {$this->connectionId} not found, skipping sync");
            return;
        }

        if (!$connection->sync_enabled) {
            Log::info("Sync disabled for calendar connection {$this->connectionId}, skipping");
            return;
        }

        try {
            Log::info("Starting calendar sync for connection {$this->connectionId}");

            $stats = $googleCalendar->syncEvents($connection);

            Log::info("Calendar sync completed for connection {$this->connectionId}", $stats);

        } catch (\Exception $e) {
            Log::error("Calendar sync failed for connection {$this->connectionId}: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }
}
