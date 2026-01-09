<?php

namespace App\Jobs;

use App\Models\Appointment;
use App\Models\Artist;
use App\Models\CalendarConnection;
use App\Services\GoogleCalendarService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncAppointmentToGoogle implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60];

    public function __construct(
        public int $appointmentId,
        public string $action = 'upsert' // upsert, delete
    ) {}

    public function handle(GoogleCalendarService $googleCalendar): void
    {
        $appointment = Appointment::with('artist', 'client')->find($this->appointmentId);

        if (!$appointment) {
            Log::warning("Appointment {$this->appointmentId} not found, skipping Google Calendar sync");
            return;
        }

        // Get the artist for CalendarConnection lookup
        $artist = $appointment->artist;
        if (!$artist) {
            Log::warning("Artist not found for appointment {$this->appointmentId}, skipping Google Calendar sync");
            return;
        }

        $connection = CalendarConnection::where('user_id', $artist->id)
            ->where('provider', 'google')
            ->where('sync_enabled', true)
            ->first();

        if (!$connection) {
            Log::debug("No Google Calendar connection for user {$artist->id} (artist {$appointment->artist_id}), skipping sync");
            return;
        }

        try {
            if ($this->action === 'delete') {
                if ($appointment->google_event_id) {
                    $googleCalendar->deleteEvent($connection, $appointment->google_event_id);
                    $appointment->update(['google_event_id' => null]);
                    Log::info("Deleted Google Calendar event for appointment {$this->appointmentId}");
                }
            } else {
                $googleCalendar->updateEventFromAppointment($connection, $appointment);
                Log::info("Synced appointment {$this->appointmentId} to Google Calendar");
            }
        } catch (\Exception $e) {
            Log::error("Failed to sync appointment {$this->appointmentId} to Google Calendar: " . $e->getMessage());
            throw $e;
        }
    }
}
