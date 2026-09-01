<?php

namespace Tests\Unit;

use App\Models\Appointment;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Traits\RefreshTestDatabase;

class EventTitleTest extends TestCase
{
    use RefreshTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * The bug this exists for. An artist naming an event reached Google as
     * "Tattoo Appointment - Client" regardless, because the title was composed
     * unconditionally and theirs was never read.
     */
    public function test_it_uses_the_title_the_artist_typed(): void
    {
        $appointment = $this->appointment(['title' => 'Appointment with Beverly']);

        $this->assertSame('Appointment with Beverly', $this->buildTitle($appointment));
    }

    /**
     * The create form fills these in when the field is left blank, so they mean
     * the artist did not name it. The composed title carries the client's name
     * and is more use on a calendar than the bare word.
     */
    public function test_it_composes_a_title_when_the_field_was_left_blank(): void
    {
        foreach (['Appointment', 'Consultation', 'Busy', ''] as $generic) {
            $appointment = $this->appointment(['title' => $generic]);

            $this->assertSame('Tattoo Appointment - Client', $this->buildTitle($appointment));
        }
    }

    public function test_it_uses_the_client_name_when_composing(): void
    {
        $client = User::factory()->create(['name' => 'Beverly']);
        $appointment = $this->appointment(['title' => 'Appointment', 'client_id' => $client->id]);

        $this->assertSame('Tattoo Appointment - Beverly', $this->buildTitle($appointment->fresh()));
    }

    public function test_a_consultation_composes_its_own_wording(): void
    {
        $appointment = $this->appointment(['title' => 'Consultation', 'type' => 'consultation']);

        $this->assertSame('Consultation - Client', $this->buildTitle($appointment));
    }

    private function buildTitle(Appointment $appointment): string
    {
        $method = new ReflectionMethod(GoogleCalendarService::class, 'buildEventTitle');
        $method->setAccessible(true);

        return $method->invoke(new GoogleCalendarService, $appointment);
    }

    private function appointment(array $overrides = []): Appointment
    {
        $artist = User::factory()->asArtist()->create(['timezone' => 'America/New_York']);

        return Appointment::create(array_merge([
            'artist_id' => $artist->id,
            'title' => 'Appointment',
            'date' => '2026-09-04',
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'type' => 'tattoo',
            'status' => 'booked',
            'client_id' => null,
        ], $overrides));
    }
}
