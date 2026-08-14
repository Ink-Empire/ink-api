<?php

/**
 * Scheduling: working hours in, bookable slots out.
 *
 * The money question is whether a client can ever be offered a slot the artist
 * cannot honour, so these lean on the conflict rules - existing bookings,
 * pending requests, personal blocking time, and the consultation window.
 */

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\ArtistAvailability;
use App\Models\ArtistSettings;
use App\Models\CalendarConnection;
use App\Models\ExternalCalendarEvent;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->artist = User::factory()->asArtist()->create(['email_verified_at' => now()]);
    $this->client = User::factory()->create(['email_verified_at' => now()]);

    // Book against a fixed future Wednesday so day-of-week never drifts.
    $this->date = Carbon::parse('next wednesday')->addWeek()->format('Y-m-d');
    $this->dayOfWeek = Carbon::parse($this->date)->dayOfWeek;

    $this->giveWorkingHours = function (array $overrides = []) {
        return ArtistAvailability::create(array_merge([
            'artist_id' => $this->artist->id,
            'day_of_week' => $this->dayOfWeek,
            'start_time' => '10:00:00',
            'end_time' => '16:00:00',
            'is_day_off' => false,
        ], $overrides));
    };

    $this->bookAppointment = function (string $start, string $end, $status = AppointmentStatus::BOOKED, ?int $clientId = null) {
        return Appointment::create([
            'title' => 'Existing work',
            'artist_id' => $this->artist->id,
            'client_id' => $clientId ?? $this->client->id,
            'date' => $this->date,
            'start_time' => $start,
            'end_time' => $end,
            'type' => 'tattoo',
            'status' => $status,
        ]);
    };

    $this->giveSyncedCalendar = function (bool $syncEnabled = true) {
        return CalendarConnection::create([
            'user_id' => $this->artist->id,
            'provider' => 'google',
            'provider_account_id' => 'acct-' . $this->artist->id,
            'provider_email' => $this->artist->email,
            'calendar_id' => 'primary',
            'access_token' => 'test-access-token',
            'refresh_token' => 'test-refresh-token',
            'token_expires_at' => now()->addHour(),
            'sync_enabled' => $syncEnabled,
        ]);
    };

    $this->slotsFor = function (string $type = 'appointment') {
        return $this->getJson("/api/artists/{$this->artist->id}/available-slots?date={$this->date}&type={$type}")
            ->assertSuccessful()
            ->json('slots');
    };
});

describe('Working hours drive the slots', function () {

    it('offers no slots when the artist has no hours for that day', function () {
        expect(($this->slotsFor)())->toBe([]);
    });

    it('offers no slots on a day marked off', function () {
        ($this->giveWorkingHours)(['is_day_off' => true]);

        expect(($this->slotsFor)())->toBe([]);
    });

    it('generates slots across the working day', function () {
        ($this->giveWorkingHours)();

        $slots = ($this->slotsFor)();

        expect($slots)->not->toBeEmpty()
            ->and($slots)->toContain('10:00')
            ->and($slots)->toContain('15:30');
    });

    it('never offers a slot before opening or after closing', function () {
        ($this->giveWorkingHours)();

        $slots = ($this->slotsFor)();

        foreach ($slots as $slot) {
            expect($slot >= '10:00')->toBeTrue()
                ->and($slot < '16:00')->toBeTrue();
        }
    });

    it('reports the working hours alongside the slots', function () {
        ($this->giveWorkingHours)();

        $this->getJson("/api/artists/{$this->artist->id}/available-slots?date={$this->date}")
            ->assertSuccessful()
            ->assertJsonPath('working_hours.start', '10:00')
            ->assertJsonPath('working_hours.end', '16:00');
    });
});

describe('Existing commitments block slots', function () {

    beforeEach(function () {
        ($this->giveWorkingHours)();
    });

    it('removes slots overlapping a booked appointment', function () {
        ($this->bookAppointment)('12:00:00', '14:00:00');

        $slots = ($this->slotsFor)();

        expect($slots)->not->toContain('12:00')
            ->and($slots)->not->toContain('13:00')
            ->and($slots)->not->toContain('13:30')
            ->and($slots)->toContain('10:00');
    });

    it('also blocks on a pending request, so two clients cannot claim one slot', function () {
        ($this->bookAppointment)('12:00:00', '14:00:00', AppointmentStatus::PENDING);

        expect(($this->slotsFor)())->not->toContain('12:00');
    });

    it('frees the slot again once an appointment is cancelled', function () {
        $appointment = ($this->bookAppointment)('12:00:00', '14:00:00');

        expect(($this->slotsFor)())->not->toContain('12:00');

        $appointment->update(['status' => AppointmentStatus::CANCELLED]);

        expect(($this->slotsFor)())->toContain('12:00');
    });

    it('blocks time the artist reserved for themselves', function () {
        // Personal blocking time is an appointment with no client
        Appointment::create([
            'title' => 'Dentist',
            'artist_id' => $this->artist->id,
            'client_id' => null,
            'date' => $this->date,
            'start_time' => '11:00:00',
            'end_time' => '12:00:00',
            'type' => 'other',
            'status' => AppointmentStatus::BOOKED,
        ]);

        expect(($this->slotsFor)())->not->toContain('11:00');
    });

    it('blocks time taken by a synced external calendar event', function () {
        $connection = ($this->giveSyncedCalendar)();

        ExternalCalendarEvent::create([
            'calendar_connection_id' => $connection->id,
            'vendor_event_id' => 'ext-1',
            'title' => 'Dentist',
            'starts_at' => $this->date . ' 11:00:00',
            'ends_at' => $this->date . ' 12:00:00',
            'all_day' => false,
            'status' => 'confirmed',
            'source' => 'google',
        ]);

        $slots = ($this->slotsFor)();

        expect($slots)->not->toContain('11:00')
            ->and($slots)->not->toContain('11:30')
            ->and($slots)->toContain('10:00');
    });

    it('ignores a cancelled external event', function () {
        $connection = ($this->giveSyncedCalendar)();

        ExternalCalendarEvent::create([
            'calendar_connection_id' => $connection->id,
            'vendor_event_id' => 'ext-2',
            'title' => 'Cancelled thing',
            'starts_at' => $this->date . ' 11:00:00',
            'ends_at' => $this->date . ' 12:00:00',
            'all_day' => false,
            'status' => 'cancelled',
            'source' => 'google',
        ]);

        expect(($this->slotsFor)())->toContain('11:00');
    });

    it('clears the whole day for an all-day external event', function () {
        $connection = ($this->giveSyncedCalendar)();

        ExternalCalendarEvent::create([
            'calendar_connection_id' => $connection->id,
            'vendor_event_id' => 'ext-3',
            'title' => 'On holiday',
            'starts_at' => $this->date . ' 00:00:00',
            'ends_at' => Carbon::parse($this->date)->addDay()->format('Y-m-d') . ' 00:00:00',
            'all_day' => true,
            'status' => 'confirmed',
            'source' => 'google',
        ]);

        expect(($this->slotsFor)())->toBe([]);
    });

    it('ignores external events when sync is switched off', function () {
        $connection = ($this->giveSyncedCalendar)(false);

        ExternalCalendarEvent::create([
            'calendar_connection_id' => $connection->id,
            'vendor_event_id' => 'ext-4',
            'title' => 'Stale event',
            'starts_at' => $this->date . ' 11:00:00',
            'ends_at' => $this->date . ' 12:00:00',
            'all_day' => false,
            'status' => 'confirmed',
            'source' => 'google',
        ]);

        expect(($this->slotsFor)())->toContain('11:00');
    });

    it('leaves another artist calendar untouched', function () {
        $otherArtist = User::factory()->asArtist()->create(['email_verified_at' => now()]);
        ArtistAvailability::create([
            'artist_id' => $otherArtist->id,
            'day_of_week' => $this->dayOfWeek,
            'start_time' => '10:00:00',
            'end_time' => '16:00:00',
            'is_day_off' => false,
        ]);

        ($this->bookAppointment)('12:00:00', '14:00:00');

        $otherSlots = $this->getJson("/api/artists/{$otherArtist->id}/available-slots?date={$this->date}")
            ->assertSuccessful()
            ->json('slots');

        expect($otherSlots)->toContain('12:00');
    });
});

describe('Consultations', function () {

    it('offers consultation slots inside the consultation window', function () {
        ($this->giveWorkingHours)([
            'consultation_start_time' => '10:00:00',
            'consultation_end_time' => '11:00:00',
        ]);
        ArtistSettings::create(['artist_id' => $this->artist->id, 'consultation_duration' => 30]);

        $slots = ($this->slotsFor)('consultation');

        expect($slots)->toContain('10:00')
            ->and($slots)->toContain('10:30')
            ->and($slots)->not->toContain('11:00');
    });

    it('keeps the consultation window out of regular appointment slots', function () {
        ($this->giveWorkingHours)([
            'consultation_start_time' => '10:00:00',
            'consultation_end_time' => '11:00:00',
        ]);

        $slots = ($this->slotsFor)();

        expect($slots)->not->toContain('10:00')
            ->and($slots)->not->toContain('10:30')
            ->and($slots)->toContain('11:00');
    });
});

describe('Setting working hours', function () {

    it('lets an artist publish their week', function () {
        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/artists/{$this->artist->id}/working-hours", [
                'availability' => [
                    ['day_of_week' => $this->dayOfWeek, 'start_time' => '09:00', 'end_time' => '17:00', 'is_day_off' => false],
                ],
            ])
            ->assertSuccessful();

        expect(ArtistAvailability::where('artist_id', $this->artist->id)
            ->where('day_of_week', $this->dayOfWeek)
            ->exists())->toBeTrue();
    });

    it('updates existing hours rather than stacking duplicates', function () {
        ($this->giveWorkingHours)();

        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/artists/{$this->artist->id}/working-hours", [
                'availability' => [
                    ['day_of_week' => $this->dayOfWeek, 'start_time' => '08:00', 'end_time' => '12:00', 'is_day_off' => false],
                ],
            ])
            ->assertSuccessful();

        $rows = ArtistAvailability::where('artist_id', $this->artist->id)
            ->where('day_of_week', $this->dayOfWeek)
            ->get();

        expect($rows)->toHaveCount(1)
            ->and(substr($rows->first()->start_time, 0, 5))->toBe('08:00');
    });

    it('requires authentication to change hours', function () {
        $this->postJson("/api/artists/{$this->artist->id}/working-hours", [
            'availability' => [
                ['day_of_week' => $this->dayOfWeek, 'start_time' => '09:00', 'end_time' => '17:00', 'is_day_off' => false],
            ],
        ])->assertStatus(401);
    });
});

describe('Request validation', function () {

    it('requires a date', function () {
        $this->getJson("/api/artists/{$this->artist->id}/available-slots")
            ->assertStatus(400);
    });

    it('404s for an artist that does not exist', function () {
        $this->getJson("/api/artists/999999/available-slots?date={$this->date}")
            ->assertStatus(404);
    });
});
