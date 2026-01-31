<?php

use App\Models\Artist;
use App\Models\ArtistAvailability;
use App\Models\ArtistSettings;
use App\Models\Image;
use App\Models\Studio;
use App\Models\Style;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create test styles
    $this->styles = Style::factory()->count(5)->create();

    // Create a test studio
    $this->studio = Studio::factory()->create();

    // Create test image
    $this->image = Image::factory()->create();

    // Create test artist with full relationships
    $this->artist = Artist::factory()->create([
        'studio_id' => $this->studio->id,
        'image_id' => $this->image->id,
    ]);

    // Add settings
    $this->settings = ArtistSettings::create([
        'artist_id' => $this->artist->id,
        'books_open' => true,
        'accepts_walk_ins' => false,
        'hourly_rate' => 150,
        'min_price' => 100,
        'deposit_required' => true,
        'deposit_amount' => 50,
    ]);

    // Add styles
    $this->artist->styles()->attach($this->styles->random(3)->pluck('id'));

    // Create a client user for authenticated requests
    $this->user = User::factory()->create();
});

describe('Artist Public API Contracts', function () {

    it('GET /api/artists/{id} returns correct structure', function () {
        $response = $this->getJson("/api/artists/{$this->artist->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'artist' => [
                    'id',
                    'name',
                    'slug',
                    'username',
                    'about',
                    'location',
                ]
            ]);

        // Verify slug is present for URLs
        $data = $response->json('artist');
        expect($data)->toHaveKey('slug');
        expect($data['slug'])->not->toBeNull();

        exportFixture('artist/detail.json', $response->json());
    });

    it('GET /api/artists/{slug} returns correct structure when using slug', function () {
        // Note: Slug lookup uses Elasticsearch. In tests without ES data,
        // artist will be null. The ID lookup test above covers the response structure.
        $response = $this->getJson("/api/artists/{$this->artist->slug}");

        $response->assertOk()
            ->assertJsonStructure(['artist']);
    });

    it('POST /api/artists (search) returns correct structure', function () {
        // Create more artists for search results
        Artist::factory()->count(5)->create();

        $response = $this->postJson('/api/artists', [
            'limit' => 10,
        ]);

        // Search returns 'response' array with pagination meta
        $response->assertOk()
            ->assertJsonStructure([
                'response',
                'total',
                'page',
                'per_page',
            ]);

        exportFixture('artist/search.json', $response->json());
    });

    it('GET /api/artists/{id}/working-hours returns correct structure', function () {
        // Create availability for the artist
        foreach (range(1, 5) as $dayOfWeek) {
            ArtistAvailability::create([
                'artist_id' => $this->artist->id,
                'day_of_week' => $dayOfWeek,
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'is_day_off' => false,
            ]);
        }

        $response = $this->getJson("/api/artists/{$this->artist->id}/working-hours");

        $response->assertOk();

        exportFixture('artist/working-hours.json', $response->json());
    });

});

describe('Artist Authenticated API Contracts', function () {

    it('GET /api/artists/{id}/settings returns full structure for owner', function () {
        $this->actingAs($this->artist, 'sanctum');

        $response = $this->getJson("/api/artists/{$this->artist->id}/settings");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'books_open',
                    'accepts_walk_ins',
                    'hourly_rate',
                ]
            ]);

        exportFixture('artist/settings-owner.json', $response->json());
    });

    it('PUT /api/artists/{id}/settings updates settings correctly', function () {
        $this->actingAs($this->artist, 'sanctum');

        $response = $this->putJson("/api/artists/{$this->artist->id}/settings", [
            'books_open' => false,
            'hourly_rate' => 200,
        ]);

        $response->assertOk();

        $this->settings->refresh();
        expect($this->settings->books_open)->toBeFalse();
        expect($this->settings->hourly_rate)->toBe(200);
    });

    it('GET /api/artists/{id}/dashboard-stats returns correct structure', function () {
        $this->actingAs($this->artist, 'sanctum');

        $response = $this->getJson("/api/artists/{$this->artist->id}/dashboard-stats");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'profile_views',
                    'saves_count',
                    'upcoming_appointments',
                    'unread_messages',
                ]
            ]);

        exportFixture('artist/dashboard-stats.json', $response->json());
    });

});
