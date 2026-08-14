<?php

/**
 * Messaging: conversations, structured business cards, and read/delete state.
 *
 * The inbox is the deal surface, so these cover the parts that carry money and
 * scheduling - quotes, deposits, reschedules - plus the per-user visibility
 * rules that keep one person's deletions from affecting the other.
 */

use App\Enums\AppointmentStatus;
use App\Events\MessageSent;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    // Broadcasting is configured for Pusher locally; fake it so tests neither
    // hit the network nor depend on credentials.
    Event::fake([MessageSent::class, \App\Events\ConversationUpdated::class]);

    $this->artist = User::factory()->asArtist()->create(['email_verified_at' => now()]);
    $this->client = User::factory()->create(['email_verified_at' => now()]);
    $this->outsider = User::factory()->create(['email_verified_at' => now()]);

    $this->startConversation = function (?string $initialMessage = null) {
        $payload = ['participant_id' => $this->artist->id, 'type' => 'booking'];
        if ($initialMessage) {
            $payload['initial_message'] = $initialMessage;
        }

        $this->actingAs($this->client, 'sanctum')
            ->postJson('/api/conversations', $payload)
            ->assertSuccessful();

        return Conversation::latest('id')->first();
    };
});

describe('Starting a conversation', function () {

    it('creates a conversation with both people as participants', function () {
        $conversation = ($this->startConversation)();

        $participantIds = $conversation->participants->pluck('user_id')->all();

        expect($participantIds)->toContain($this->client->id)
            ->and($participantIds)->toContain($this->artist->id);
    });

    it('posts the initial message when one is supplied', function () {
        $conversation = ($this->startConversation)('Hi, I am after a dragon half sleeve.');

        $message = Message::where('conversation_id', $conversation->id)->first();

        expect($message)->not->toBeNull()
            ->and($message->content)->toBe('Hi, I am after a dragon half sleeve.')
            ->and($message->sender_id)->toBe($this->client->id);
    });

    it('reuses the existing conversation for the same pair', function () {
        $first = ($this->startConversation)();
        $second = ($this->startConversation)();

        expect($second->id)->toBe($first->id);
    });

    it('refuses to open a conversation with someone who blocked you', function () {
        $this->artist->blockedUsers()->attach($this->client->id);

        $this->actingAs($this->client, 'sanctum')
            ->postJson('/api/conversations', [
                'participant_id' => $this->artist->id,
                'type' => 'booking',
            ])
            ->assertStatus(403);
    });

    it('rejects a participant that does not exist', function () {
        $this->actingAs($this->client, 'sanctum')
            ->postJson('/api/conversations', ['participant_id' => 999999])
            ->assertStatus(422);
    });

    it('requires authentication', function () {
        $this->postJson('/api/conversations', ['participant_id' => $this->artist->id])
            ->assertStatus(401);
    });
});

describe('Sending messages', function () {

    beforeEach(function () {
        $this->conversation = ($this->startConversation)();
    });

    it('delivers a text message into the thread', function () {
        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/conversations/{$this->conversation->id}/messages", [
                'content' => 'Happy to take that on - sending a quote.',
            ])
            ->assertStatus(201);

        $message = Message::where('conversation_id', $this->conversation->id)
            ->latest('id')
            ->first();

        expect($message->content)->toBe('Happy to take that on - sending a quote.')
            ->and($message->sender_id)->toBe($this->artist->id);
    });

    it('broadcasts the message for real-time delivery', function () {
        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/conversations/{$this->conversation->id}/messages", [
                'content' => 'On my way.',
            ])
            ->assertStatus(201);

        Event::assertDispatched(MessageSent::class);
    });

    it('rejects an empty message with no attachments', function () {
        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/conversations/{$this->conversation->id}/messages", ['content' => ''])
            ->assertStatus(422);
    });

    it('refuses to send once the other person has blocked you', function () {
        $this->artist->blockedUsers()->attach($this->client->id);

        $this->actingAs($this->client, 'sanctum')
            ->postJson("/api/conversations/{$this->conversation->id}/messages", [
                'content' => 'Still interested?',
            ])
            ->assertStatus(403);
    });

    it('hides the conversation from people who are not in it', function () {
        $this->actingAs($this->outsider, 'sanctum')
            ->postJson("/api/conversations/{$this->conversation->id}/messages", [
                'content' => 'Let me in.',
            ])
            ->assertStatus(404);
    });
});

describe('Structured business cards', function () {

    beforeEach(function () {
        $this->conversation = ($this->startConversation)();
    });

    it('sends an itemized price quote', function () {
        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/conversations/{$this->conversation->id}/messages/price-quote", [
                'items' => [
                    ['description' => 'Custom dragon design', 'amount' => '150'],
                    ['description' => 'Session 1 - outline', 'amount' => '600'],
                ],
                'total' => '750',
                'notes' => 'Two sessions, three weeks apart.',
            ])
            ->assertStatus(201);

        $message = Message::where('conversation_id', $this->conversation->id)
            ->where('type', 'price_quote')
            ->first();

        expect($message)->not->toBeNull()
            ->and($message->metadata['total'])->toBe('750')
            ->and($message->metadata['items'])->toHaveCount(2);
    });

    it('rejects a price quote with no line items', function () {
        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/conversations/{$this->conversation->id}/messages/price-quote", [
                'total' => '750',
            ])
            ->assertStatus(422);
    });

    it('sends a deposit request carrying the amount', function () {
        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/conversations/{$this->conversation->id}/messages/deposit-request", [
                'amount' => '150',
            ])
            ->assertStatus(201);

        $message = Message::where('conversation_id', $this->conversation->id)
            ->where('type', 'deposit_request')
            ->first();

        expect($message)->not->toBeNull()
            ->and($message->metadata['amount'])->toBe('150');
    });

    it('sends a booking card with the appointment details', function () {
        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/conversations/{$this->conversation->id}/messages/booking-card", [
                'date' => 'Fri, Aug 14, 2026',
                'time' => '10:00 AM - 2:00 PM',
                'duration' => '4 hours',
                'deposit_amount' => '150',
            ])
            ->assertStatus(201);

        expect(Message::where('conversation_id', $this->conversation->id)
            ->where('type', 'booking_card')
            ->exists())->toBeTrue();
    });
});

describe('Rescheduling through the inbox', function () {

    beforeEach(function () {
        $this->conversation = ($this->startConversation)();

        $this->appointment = Appointment::create([
            'title' => 'Dragon half sleeve',
            'artist_id' => $this->artist->id,
            'client_id' => $this->client->id,
            'date' => now()->addWeek()->format('Y-m-d'),
            'start_time' => '10:00:00',
            'end_time' => '14:00:00',
            'type' => 'tattoo',
            'status' => AppointmentStatus::BOOKED,
        ]);

        $this->newDate = now()->addWeeks(3)->format('Y-m-d');

        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/conversations/{$this->conversation->id}/messages/reschedule", [
                'appointment_id' => $this->appointment->id,
                'proposed_date' => $this->newDate,
                'proposed_start_time' => '12:00',
                'proposed_end_time' => '16:00',
                'reason' => 'Studio double-booked that morning.',
            ])
            ->assertStatus(201);

        $this->rescheduleMessage = Message::where('conversation_id', $this->conversation->id)
            ->where('type', 'reschedule')
            ->latest('id')
            ->first();
    });

    it('creates a pending reschedule request', function () {
        expect($this->rescheduleMessage)->not->toBeNull()
            ->and($this->rescheduleMessage->metadata['status'])->toBe('pending');
    });

    it('moves the appointment when the client accepts', function () {
        $this->actingAs($this->client, 'sanctum')
            ->putJson("/api/conversations/{$this->conversation->id}/messages/{$this->rescheduleMessage->id}/respond", [
                'action' => 'accept',
            ])
            ->assertSuccessful();

        $appointment = $this->appointment->fresh();

        expect($appointment->date->format('Y-m-d'))->toBe($this->newDate)
            ->and(substr($appointment->start_time, 0, 5))->toBe('12:00');
    });

    it('leaves the appointment alone when the client declines', function () {
        $originalDate = $this->appointment->date->format('Y-m-d');

        $this->actingAs($this->client, 'sanctum')
            ->putJson("/api/conversations/{$this->conversation->id}/messages/{$this->rescheduleMessage->id}/respond", [
                'action' => 'decline',
            ])
            ->assertSuccessful();

        expect($this->appointment->fresh()->date->format('Y-m-d'))->toBe($originalDate);
    });

    it('stops the sender responding to their own request', function () {
        $this->actingAs($this->artist, 'sanctum')
            ->putJson("/api/conversations/{$this->conversation->id}/messages/{$this->rescheduleMessage->id}/respond", [
                'action' => 'accept',
            ])
            ->assertStatus(403);
    });

    it('refuses a second response to the same request', function () {
        $this->actingAs($this->client, 'sanctum')
            ->putJson("/api/conversations/{$this->conversation->id}/messages/{$this->rescheduleMessage->id}/respond", [
                'action' => 'accept',
            ])
            ->assertSuccessful();

        $this->actingAs($this->client, 'sanctum')
            ->putJson("/api/conversations/{$this->conversation->id}/messages/{$this->rescheduleMessage->id}/respond", [
                'action' => 'decline',
            ])
            ->assertStatus(422);
    });
});

describe('Unread counts and read state', function () {

    beforeEach(function () {
        $this->conversation = ($this->startConversation)();

        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/conversations/{$this->conversation->id}/messages", [
                'content' => 'Quote incoming.',
            ])
            ->assertStatus(201);
    });

    it('counts messages the recipient has not read', function () {
        $response = $this->actingAs($this->client, 'sanctum')
            ->getJson('/api/conversations/unread-count')
            ->assertSuccessful();

        expect($response->json('unread_count'))->toBeGreaterThan(0);
    });

    it('does not count your own messages as unread', function () {
        $response = $this->actingAs($this->artist, 'sanctum')
            ->getJson('/api/conversations/unread-count')
            ->assertSuccessful();

        expect($response->json('unread_count'))->toBe(0);
    });

    it('clears the count once the conversation is read', function () {
        $this->actingAs($this->client, 'sanctum')
            ->putJson("/api/conversations/{$this->conversation->id}/read")
            ->assertSuccessful();

        $response = $this->actingAs($this->client, 'sanctum')
            ->getJson('/api/conversations/unread-count')
            ->assertSuccessful();

        expect($response->json('unread_count'))->toBe(0);
    });
});

describe('Per-user visibility', function () {

    beforeEach(function () {
        $this->conversation = ($this->startConversation)('First message');
    });

    it('hides a deleted conversation from the deleter only', function () {
        $this->actingAs($this->client, 'sanctum')
            ->deleteJson("/api/conversations/{$this->conversation->id}")
            ->assertSuccessful();

        $this->actingAs($this->client, 'sanctum')
            ->getJson('/api/conversations')
            ->assertSuccessful()
            ->assertJsonMissing(['id' => $this->conversation->id]);

        // The artist still has the thread
        $this->actingAs($this->artist, 'sanctum')
            ->getJson("/api/conversations/{$this->conversation->id}")
            ->assertSuccessful();
    });

    it('hides a deleted message from the deleter only', function () {
        $message = Message::where('conversation_id', $this->conversation->id)->first();

        $this->actingAs($this->client, 'sanctum')
            ->deleteJson("/api/conversations/{$this->conversation->id}/messages/{$message->id}")
            ->assertSuccessful();

        // Gone for the person who deleted it...
        $this->actingAs($this->client, 'sanctum')
            ->getJson("/api/conversations/{$this->conversation->id}/messages")
            ->assertSuccessful()
            ->assertDontSee('First message');

        // ...but still there for the other person
        $this->actingAs($this->artist, 'sanctum')
            ->getJson("/api/conversations/{$this->conversation->id}/messages")
            ->assertSuccessful()
            ->assertSee('First message');
    });
});
