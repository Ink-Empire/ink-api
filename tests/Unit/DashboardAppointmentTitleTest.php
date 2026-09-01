<?php

namespace Tests\Unit;

use App\Models\Appointment;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Traits\RefreshTestDatabase;

class DashboardAppointmentTitleTest extends TestCase
{
    use RefreshTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    /**
     * The Google event title was fixed first, which left the two surfaces
     * disagreeing. Google showed "appointment with lentil" while the artist's
     * own calendar still said "Tattoo Appointment with Unknown Client".
     */
    public function test_it_uses_the_title_the_artist_typed(): void
    {
        $appointment = $this->appointment(['title' => 'appointment with lentil']);

        $this->assertSame('appointment with lentil', $this->formatTitle($appointment));
    }

    public function test_it_composes_a_title_when_the_field_was_left_blank(): void
    {
        foreach (['Appointment', 'Consultation', 'Busy', ''] as $generic) {
            $appointment = $this->appointment(['title' => $generic]);

            $this->assertSame('Tattoo Appointment with Unknown Client', $this->formatTitle($appointment));
        }
    }

    public function test_it_names_the_client_when_composing(): void
    {
        $client = User::factory()->create(['name' => 'Beverly']);
        $appointment = $this->appointment(['title' => 'Appointment', 'client_id' => $client->id]);

        $this->assertSame('Tattoo Appointment with Beverly', $this->formatTitle($appointment->fresh()));
    }

    /**
     * Both surfaces have to agree on what counts as an untitled appointment,
     * which is why the rule lives on the model rather than in each service.
     */
    public function test_the_model_decides_what_counts_as_a_custom_title(): void
    {
        $this->assertTrue($this->appointment(['title' => 'appointment with lentil'])->hasCustomTitle());
        $this->assertFalse($this->appointment(['title' => 'Appointment'])->hasCustomTitle());
        $this->assertFalse($this->appointment(['title' => '  busy  '])->hasCustomTitle());
    }

    private function formatTitle(Appointment $appointment): string
    {
        $method = new ReflectionMethod(DashboardService::class, 'formatAppointmentTitle');
        $method->setAccessible(true);

        return $method->invoke(app(DashboardService::class), $appointment);
    }

    private function appointment(array $overrides = []): Appointment
    {
        $artist = User::factory()->asArtist()->create(['timezone' => 'America/New_York']);

        return Appointment::create(array_merge([
            'artist_id' => $artist->id,
            'title' => 'Appointment',
            'date' => '2026-09-03',
            'start_time' => '15:00:00',
            'end_time' => '16:00:00',
            'type' => 'tattoo',
            'status' => 'booked',
            'client_id' => null,
        ], $overrides));
    }
}
