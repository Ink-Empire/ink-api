<?php

namespace Tests\Unit\Jobs;

use App\Jobs\DeleteGoogleCalendarEvent;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\RefreshTestDatabase;

class CalendarDeletionTest extends TestCase
{
    use RefreshTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * The bug this exists for. The deleted hook used to queue a job that looked
     * the appointment up by id, which cannot work once the row is gone, so the
     * event stayed on the artist's Google calendar forever.
     */
    public function test_deleting_an_appointment_queues_the_google_event_for_removal(): void
    {
        $appointment = $this->appointment(['google_event_id' => 'google-event-123']);
        $artistId = $appointment->artist_id;

        $appointment->delete();

        Queue::assertPushed(DeleteGoogleCalendarEvent::class, function ($job) use ($artistId) {
            return $job->googleEventId === 'google-event-123' && $job->artistId === $artistId;
        });
    }

    /**
     * The job has to carry everything it needs, because the row it came from no
     * longer exists by the time it runs.
     */
    public function test_the_queued_job_survives_the_appointment_being_gone(): void
    {
        $appointment = $this->appointment(['google_event_id' => 'google-event-456']);
        $appointmentId = $appointment->id;

        $appointment->delete();

        $this->assertNull(Appointment::find($appointmentId));

        Queue::assertPushed(DeleteGoogleCalendarEvent::class, function ($job) {
            return $job->googleEventId === 'google-event-456';
        });
    }

    public function test_it_queues_nothing_when_the_appointment_never_reached_google(): void
    {
        $this->appointment(['google_event_id' => null])->delete();

        Queue::assertNotPushed(DeleteGoogleCalendarEvent::class);
    }

    /**
     * An artist deleting their own blocked-out time in Google means they want
     * that time back, so it goes here too.
     */
    public function test_a_personal_block_deleted_in_google_is_deleted_here(): void
    {
        $appointment = $this->appointment(['google_event_id' => 'google-event-789']);

        $this->assertSame('deleted', $this->cancelledInGoogle($appointment->id));
        $this->assertNull(Appointment::find($appointment->id));
    }

    /**
     * The case worth being careful about. An event disappearing from a calendar
     * is not a decision to cancel on a paying client, so the booking stays.
     */
    public function test_a_client_booking_deleted_in_google_is_left_alone(): void
    {
        $client = User::factory()->create();
        $appointment = $this->appointment([
            'google_event_id' => 'google-event-999',
            'client_id' => $client->id,
        ]);

        $this->assertSame('updated', $this->cancelledInGoogle($appointment->id));
        $this->assertNotNull(Appointment::find($appointment->id));
    }

    public function test_it_copes_with_an_appointment_that_is_already_gone(): void
    {
        $this->assertSame('updated', $this->cancelledInGoogle(999999));
    }

    private function cancelledInGoogle(int $appointmentId): string
    {
        $service = new \App\Services\GoogleCalendarService;
        $method = new \ReflectionMethod($service, 'cancelledInGoogle');
        $method->setAccessible(true);

        return $method->invoke($service, $appointmentId);
    }

    private function appointment(array $overrides = []): Appointment
    {
        $artist = User::factory()->asArtist()->create(['timezone' => 'America/New_York']);

        return Appointment::create(array_merge([
            'artist_id' => $artist->id,
            'title' => 'Tattoo Appointment',
            'date' => '2026-09-03',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'type' => 'tattoo',
            'status' => 'booked',
            'client_id' => null,
        ], $overrides));
    }
}
