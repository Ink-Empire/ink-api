<?php

/**
 * Booking lifecycle: the full client -> artist interaction chain.
 *
 * Covers the seams that unit tests miss: creating an appointment must also
 * open a conversation, post a booking card, and notify the artist; responding
 * must move state, notify the other party, and update the card in place.
 */

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Artist;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Notifications\BookingAcceptedNotification;
use App\Notifications\BookingDeclinedNotification;
use App\Notifications\BookingRequestNotification;
use App\Notifications\GuestAppointmentInviteNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->artist = User::factory()->asArtist()->create(['email_verified_at' => now()]);
    $this->client = User::factory()->create(['email_verified_at' => now()]);

    // The app notifies the Artist model, and the notification fake matches on
    // exact class, so assertions need the Artist instance rather than User.
    $this->artistNotifiable = Artist::find($this->artist->id);

    $this->bookingPayload = [
        'artist_id' => $this->artist->id,
        'client_id' => $this->client->id,
        'type' => 'tattoo',
        'title' => 'Dragon half sleeve - session 1',
        'date' => now()->addWeek()->format('Y-m-d'),
        'start_time' => '10:00',
        'end_time' => '14:00',
        'description' => 'First session, outline and start shading.',
    ];
});

describe('Requesting an appointment', function () {

    it('creates the appointment in pending status', function () {
        $this->actingAs($this->client, 'sanctum')
            ->postJson('/api/appointments/create', $this->bookingPayload)
            ->assertOk();

        $appointment = Appointment::latest('id')->first();

        expect($appointment)->not->toBeNull()
            ->and($appointment->artist_id)->toBe($this->artist->id)
            ->and($appointment->client_id)->toBe($this->client->id)
            ->and($appointment->status)->toBe(AppointmentStatus::PENDING);
    });

    it('opens a conversation between client and artist linked to the appointment', function () {
        $this->actingAs($this->client, 'sanctum')
            ->postJson('/api/appointments/create', $this->bookingPayload)
            ->assertOk();

        $appointment = Appointment::latest('id')->first();
        $conversation = Conversation::where('appointment_id', $appointment->id)->first();

        expect($conversation)->not->toBeNull();

        $participantIds = $conversation->participants->pluck('user_id')->all();
        expect($participantIds)->toContain($this->client->id)
            ->and($participantIds)->toContain($this->artist->id);
    });

    it('posts a booking card carrying the appointment details', function () {
        $this->actingAs($this->client, 'sanctum')
            ->postJson('/api/appointments/create', $this->bookingPayload)
            ->assertOk();

        $appointment = Appointment::latest('id')->first();
        $card = Message::where('appointment_id', $appointment->id)
            ->where('type', 'booking_card')
            ->first();

        expect($card)->not->toBeNull()
            ->and($card->sender_id)->toBe($this->client->id)
            ->and($card->recipient_id)->toBe($this->artist->id)
            ->and($card->metadata['status'])->toBe('pending')
            ->and($card->metadata['duration'])->toBe('4 hours');
    });

    it('notifies the artist and nobody else', function () {
        $this->actingAs($this->client, 'sanctum')
            ->postJson('/api/appointments/create', $this->bookingPayload)
            ->assertOk();

        Notification::assertSentTo($this->artistNotifiable, BookingRequestNotification::class);
        Notification::assertNotSentTo($this->client, BookingRequestNotification::class);
    });

    it('computes duration and price from the time range and hourly rate', function () {
        $this->artist->settings()->create(['hourly_rate' => 150]);

        $this->actingAs($this->client, 'sanctum')
            ->postJson('/api/appointments/create', $this->bookingPayload)
            ->assertOk();

        $appointment = Appointment::latest('id')->first();

        expect($appointment->duration_minutes)->toBe(240)
            ->and((float) $appointment->price)->toBe(600.00);
    });

    it('rejects a request that is missing required fields', function () {
        $this->actingAs($this->client, 'sanctum')
            ->postJson('/api/appointments/create', [
                'artist_id' => $this->artist->id,
                'client_id' => $this->client->id,
            ])
            ->assertStatus(422);
    });

    it('rejects a request for an artist that does not exist', function () {
        $this->actingAs($this->client, 'sanctum')
            ->postJson('/api/appointments/create', array_merge($this->bookingPayload, [
                'artist_id' => 999999,
            ]))
            ->assertStatus(404);
    });

    it('refuses to book an artist who has blocked the client', function () {
        $this->artist->blockedUsers()->attach($this->client->id);

        $this->actingAs($this->client, 'sanctum')
            ->postJson('/api/appointments/create', $this->bookingPayload)
            ->assertStatus(403);

        expect(Appointment::where('artist_id', $this->artist->id)->count())->toBe(0);
        Notification::assertNothingSent();
    });

    it('reuses an existing conversation rather than opening a second one', function () {
        $this->actingAs($this->client, 'sanctum')
            ->postJson('/api/appointments/create', $this->bookingPayload)
            ->assertOk();

        $this->actingAs($this->client, 'sanctum')
            ->postJson('/api/appointments/create', array_merge($this->bookingPayload, [
                'date' => now()->addWeeks(3)->format('Y-m-d'),
                'title' => 'Session 2',
            ]))
            ->assertOk();

        $conversationsForPair = Conversation::whereHas('participants', fn ($q) => $q->where('user_id', $this->client->id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $this->artist->id))
            ->count();

        expect(Appointment::where('artist_id', $this->artist->id)->count())->toBe(2)
            ->and($conversationsForPair)->toBe(1);
    });
});

describe('Artist responds to the request', function () {

    beforeEach(function () {
        $this->actingAs($this->client, 'sanctum')
            ->postJson('/api/appointments/create', $this->bookingPayload)
            ->assertOk();

        $this->appointment = Appointment::latest('id')->first();
        Notification::fake(); // clear the request notification
    });

    it('moves the appointment to booked on accept', function () {
        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/appointments/{$this->appointment->id}/respond", ['action' => 'accept'])
            ->assertOk();

        expect($this->appointment->fresh()->status)->toBe(AppointmentStatus::BOOKED);
    });

    it('notifies the client that the booking was accepted', function () {
        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/appointments/{$this->appointment->id}/respond", ['action' => 'accept'])
            ->assertOk();

        Notification::assertSentTo($this->client, BookingAcceptedNotification::class);
    });

    it('updates the booking card in place instead of posting a duplicate', function () {
        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/appointments/{$this->appointment->id}/respond", ['action' => 'accept'])
            ->assertOk();

        $cards = Message::where('appointment_id', $this->appointment->id)
            ->where('type', 'booking_card')
            ->get();

        expect($cards)->toHaveCount(1)
            ->and($cards->first()->metadata['status'])->toBe('accepted');
    });

    it('cancels the appointment and notifies the client on decline', function () {
        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/appointments/{$this->appointment->id}/respond", [
                'action' => 'decline',
                'reason' => 'Fully booked that month.',
            ])
            ->assertOk();

        expect($this->appointment->fresh()->status)->toBe(AppointmentStatus::CANCELLED);
        Notification::assertSentTo($this->client, BookingDeclinedNotification::class);
    });

    it('forbids a different artist from responding', function () {
        $otherArtist = User::factory()->asArtist()->create(['email_verified_at' => now()]);

        $this->actingAs($otherArtist, 'sanctum')
            ->postJson("/api/appointments/{$this->appointment->id}/respond", ['action' => 'accept'])
            ->assertStatus(403);

        expect($this->appointment->fresh()->status)->toBe(AppointmentStatus::PENDING);
        Notification::assertNothingSent();
    });

    it('forbids the client from accepting their own request', function () {
        $this->actingAs($this->client, 'sanctum')
            ->postJson("/api/appointments/{$this->appointment->id}/respond", ['action' => 'accept'])
            ->assertStatus(403);

        expect($this->appointment->fresh()->status)->toBe(AppointmentStatus::PENDING);
    });

    it('refuses to respond twice to the same request', function () {
        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/appointments/{$this->appointment->id}/respond", ['action' => 'accept'])
            ->assertOk();

        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/appointments/{$this->appointment->id}/respond", ['action' => 'accept'])
            ->assertStatus(400);
    });

    it('rejects an unknown action', function () {
        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/appointments/{$this->appointment->id}/respond", ['action' => 'maybe'])
            ->assertStatus(422);
    });
});

describe('Artist invites a guest client', function () {

    it('creates an account for a new guest and emails them a setup link', function () {
        $this->actingAs($this->artist, 'sanctum')
            ->postJson('/api/appointments/invite', [
                'artist_id' => $this->artist->id,
                'date' => now()->addWeek()->format('Y-m-d'),
                'type' => 'consultation',
                'guest_email' => 'newguest@example.com',
                'guest_name' => 'New Guest',
                'note' => 'Bring your reference images.',
            ])
            ->assertStatus(201);

        $guest = User::where('email', 'newguest@example.com')->first();

        expect($guest)->not->toBeNull()
            ->and($guest->slug)->not->toBeEmpty()
            ->and($guest->username)->not->toBeEmpty()
            ->and($guest->type_id)->toBe(\App\Enums\UserTypes::CLIENT_TYPE_ID)
            ->and(Appointment::where('client_id', $guest->id)->exists())->toBeTrue();

        Notification::assertSentTo($guest, GuestAppointmentInviteNotification::class);
    });

    it('reuses an existing user instead of creating a duplicate', function () {
        $existing = User::factory()->create([
            'email' => 'existing@example.com',
            'email_verified_at' => now(),
        ]);

        $this->actingAs($this->artist, 'sanctum')
            ->postJson('/api/appointments/invite', [
                'artist_id' => $this->artist->id,
                'date' => now()->addWeek()->format('Y-m-d'),
                'type' => 'appointment',
                'guest_email' => 'existing@example.com',
            ])
            ->assertStatus(201);

        expect(User::where('email', 'existing@example.com')->count())->toBe(1);
        Notification::assertSentTo($existing, GuestAppointmentInviteNotification::class);
    });

    it('rejects an invite without a valid email', function () {
        $this->actingAs($this->artist, 'sanctum')
            ->postJson('/api/appointments/invite', [
                'artist_id' => $this->artist->id,
                'date' => now()->addWeek()->format('Y-m-d'),
                'type' => 'consultation',
                'guest_email' => 'not-an-email',
            ])
            ->assertStatus(422);
    });
});

describe('Access control', function () {

    it('requires authentication to request an appointment', function () {
        $this->postJson('/api/appointments/create', $this->bookingPayload)
            ->assertStatus(401);
    });

    it('requires authentication to respond to an appointment', function () {
        $appointment = Appointment::create([
            'title' => 'Consultation',
            'artist_id' => $this->artist->id,
            'client_id' => $this->client->id,
            'date' => now()->addWeek()->format('Y-m-d'),
            'start_time' => '10:00:00',
            'end_time' => '11:00:00',
            'type' => 'consultation',
            'status' => AppointmentStatus::PENDING,
        ]);

        $this->postJson("/api/appointments/{$appointment->id}/respond", ['action' => 'accept'])
            ->assertStatus(401);
    });
});
