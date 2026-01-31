<?php

use App\Models\Artist;
use App\Models\ArtistSettings;
use App\Models\Image;
use App\Models\Studio;
use App\Models\Style;
use App\Models\Tattoo;
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

    // Create test artist
    $this->artist = Artist::factory()->create([
        'studio_id' => $this->studio->id,
        'image_id' => $this->image->id,
    ]);

    // Add settings
    ArtistSettings::create([
        'artist_id' => $this->artist->id,
        'books_open' => true,
    ]);

    // Create test tattoos
    $this->tattoos = Tattoo::factory()->count(5)->create([
        'artist_id' => $this->artist->id,
        'studio_id' => $this->studio->id,
        'primary_image_id' => $this->image->id,
    ]);

    // Create a client user
    $this->user = User::factory()->create();
});

describe('Tattoo Public API Contracts', function () {

    it('GET /api/tattoos/{id} returns correct structure', function () {
        $tattoo = $this->tattoos->first();

        $response = $this->getJson("/api/tattoos/{$tattoo->id}");

        // Tattoo detail uses Elasticsearch - in tests without ES data, returns null
        $response->assertOk()
            ->assertJsonStructure(['tattoo']);

        exportFixture('tattoo/detail.json', $response->json());
    });

    it('POST /api/tattoos (search) returns correct structure', function () {
        $response = $this->postJson('/api/tattoos', [
            'limit' => 10,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'response',
                'total',
                'page',
                'per_page',
            ]);

        exportFixture('tattoo/search.json', $response->json());
    });

});

describe('Tattoo Authenticated API Contracts', function () {

    it('POST /api/tattoos/create creates tattoo correctly', function () {
        $this->actingAs($this->artist, 'sanctum');

        $response = $this->postJson('/api/tattoos/create', [
            'title' => 'Test Tattoo',
            'description' => 'A beautiful test tattoo',
            'primary_image_id' => $this->image->id,
            'style_ids' => [$this->styles->first()->id],
        ]);

        // May return 201 or 200 depending on implementation
        $response->assertSuccessful();

        exportFixture('tattoo/create-response.json', $response->json());
    });

    it('PUT /api/tattoos/{id} updates tattoo correctly', function () {
        $this->actingAs($this->artist, 'sanctum');
        $tattoo = $this->tattoos->first();

        $response = $this->putJson("/api/tattoos/{$tattoo->id}", [
            'title' => 'Updated Tattoo Title',
            'description' => 'Updated description',
        ]);

        $response->assertOk();

        exportFixture('tattoo/update-response.json', $response->json());
    });

});
