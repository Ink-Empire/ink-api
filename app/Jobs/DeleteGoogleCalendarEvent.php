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

/**
 * Removes an event from an artist's Google calendar after the appointment it
 * came from has been deleted.
 *
 * This carries the event id rather than an appointment id on purpose.
 * SyncAppointmentToGoogle looks the appointment up by id, which cannot work
 * once the row is gone, so deletions dispatched from the deleted hook were
 * always skipped and the event stayed on the artist's calendar.
 */
class DeleteGoogleCalendarEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $artistId,
        public string $googleEventId
    ) {}

    public function handle(GoogleCalendarService $googleCalendar): void
    {
        $connection = CalendarConnection::where('user_id', $this->artistId)
            ->where('provider', 'google')
            ->where('sync_enabled', true)
            ->first();

        if (! $connection) {
            return;
        }

        $googleCalendar->deleteEvent($connection, $this->googleEventId);

        Log::info("Deleted Google Calendar event {$this->googleEventId} for artist {$this->artistId}");
    }
}
