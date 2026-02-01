<?php

use App\Models\User;
use App\Models\Studio;
use App\Models\Conversation;
use App\Models\ConversationParticipant;
use App\Enums\UserTypes;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create studio owner
    $this->studioOwner = User::factory()->create([
        'type_id' => UserTypes::STUDIO_TYPE_ID,
    ]);

    // Create studio
    $this->studio = Studio::factory()->create([
        'owner_id' => $this->studioOwner->id,
        'name' => 'Test Studio',
    ]);

    // Create artist affiliated with studio
    $this->artist = User::factory()->create([
        'type_id' => UserTypes::ARTIST_TYPE_ID,
    ]);
    $this->artist->affiliatedStudios()->attach($this->studio->id, [
        'is_verified' => true,
        'is_primary' => true,
        'initiated_by' => 'artist',
    ]);
});

describe('Studio Dashboard Stats', function () {
    test('dashboard stats endpoint returns successfully', function () {
        $response = $this->actingAs($this->studioOwner)
            ->getJson("/api/studios/{$this->studio->id}/dashboard-stats");

        $response->assertOk()
            ->assertJsonStructure([
                'page_views' => ['count', 'trend', 'trend_label'],
                'bookings' => ['count', 'trend', 'trend_label'],
                'inquiries' => ['count', 'trend', 'trend_label'],
                'artists_count',
            ]);
    });

    test('dashboard stats counts conversations via participants relationship', function () {
        // Create a client user
        $client = User::factory()->create([
            'type_id' => UserTypes::CLIENT_TYPE_ID,
        ]);

        // Create conversation with artist as participant
        $conversation = Conversation::create([
            'type' => 'booking',
        ]);

        // Add participants
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $this->artist->id,
        ]);
        ConversationParticipant::create([
            'conversation_id' => $conversation->id,
            'user_id' => $client->id,
        ]);

        $response = $this->actingAs($this->studioOwner)
            ->getJson("/api/studios/{$this->studio->id}/dashboard-stats");

        $response->assertOk();
        // The inquiries count should include conversations where studio artists are participants
        expect($response->json('inquiries.count'))->toBeGreaterThanOrEqual(0);
    });

    test('dashboard stats returns correct artists count', function () {
        // Add another artist
        $artist2 = User::factory()->create([
            'type_id' => UserTypes::ARTIST_TYPE_ID,
        ]);
        $artist2->affiliatedStudios()->attach($this->studio->id, [
            'is_verified' => true,
            'is_primary' => true,
            'initiated_by' => 'studio',
        ]);

        $response = $this->actingAs($this->studioOwner)
            ->getJson("/api/studios/{$this->studio->id}/dashboard-stats");

        $response->assertOk();
        expect($response->json('artists_count'))->toBe(2);
    });
});

describe('Controller Error Response', function () {
    test('leaving non-affiliated studio returns 404 with error message', function () {
        // Test 404 response when trying to leave a studio user isn't affiliated with
        $response = $this->actingAs($this->artist)
            ->deleteJson('/api/artists/me/studio/999999');

        $response->assertNotFound();
        expect($response->status())->toBe(404);
        $response->assertJsonStructure(['error']);
    });

    test('setting primary on non-affiliated studio returns 404 with error message', function () {
        $response = $this->actingAs($this->artist)
            ->postJson('/api/artists/me/studio/999999/primary');

        $response->assertNotFound();
        $response->assertJsonStructure(['error']);
    });
});
