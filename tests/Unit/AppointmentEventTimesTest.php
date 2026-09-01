<?php

namespace Tests\Unit;

use App\Models\Appointment;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\RefreshTestDatabase;

class AppointmentEventTimesTest extends TestCase
{
    use RefreshTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * The bug this exists for. A 9am appointment reached Google as 09:00 UTC
     * and rendered at 5am for an artist in New York, because the stored time
     * was parsed without a zone.
     */
    public function test_it_builds_times_in_the_artists_timezone(): void
    {
        $appointment = $this->appointment('America/New_York');

        $times = (new GoogleCalendarService)->eventTimes($appointment);

        $this->assertSame('2026-09-03T09:00:00-04:00', $times['start']['dateTime']);
        $this->assertSame('2026-09-03T10:00:00-04:00', $times['end']['dateTime']);
        $this->assertSame('America/New_York', $times['start']['timeZone']);
    }

    public function test_the_same_wall_clock_time_differs_by_timezone(): void
    {
        $newYork = (new GoogleCalendarService)->eventTimes($this->appointment('America/New_York'));
        $london = (new GoogleCalendarService)->eventTimes($this->appointment('Europe/London'));

        $this->assertSame('2026-09-03T09:00:00-04:00', $newYork['start']['dateTime']);
        $this->assertSame('2026-09-03T09:00:00+01:00', $london['start']['dateTime']);
    }

    /**
     * Not every artist has a timezone yet, so this documents the fallback
     * rather than leaving it to be discovered.
     */
    public function test_it_falls_back_to_the_app_timezone_when_the_artist_has_none(): void
    {
        $times = (new GoogleCalendarService)->eventTimes($this->appointment(null));

        $this->assertSame(config('app.timezone'), $times['start']['timeZone']);
    }

    private function appointment(?string $timezone): Appointment
    {
        $artist = User::factory()->asArtist()->create(['timezone' => $timezone]);

        return Appointment::create([
            'artist_id' => $artist->id,
            'title' => 'Tattoo Appointment',
            'date' => '2026-09-03',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'type' => 'tattoo',
            'status' => 'booked',
            'client_id' => null,
        ]);
    }
}
