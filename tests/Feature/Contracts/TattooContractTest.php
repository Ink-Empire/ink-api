<?php

use App\Models\Artist;
use App\Models\ArtistSettings;
use App\Models\Image;
use App\Models\Studio;
use App\Models\Style;
use App\Models\Tattoo;
use App\Models\User;




beforeEach(function () {
    // Create test styles
    $this->styles = Style::factory()->count(5)->create();

    // Create a test studio
    $this->studio = Studio::factory()->create();

    // Create test image
    $this->image = Image::factory()->create();

    // Create test artist
    $this->artist = Artist::factory()->create([
        'image_id' => $this->image->id,
    ]);

    // Associate artist with studio via pivot table
    $this->studio->artists()->attach($this->artist->id, ['is_verified' => true]);

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
        // Skip: Search uses Elasticsearch which is not available in test environment.
        $this->markTestSkipped('Tattoo search requires Elasticsearch');
    });

});

describe('Tattoo Authenticated API Contracts', function () {

    it('POST /api/tattoos/create creates tattoo correctly', function () {
        // Skip: This endpoint requires multipart image upload, not JSON
        // The fixture is generated manually or via integration tests
        $this->markTestSkipped('Tattoo create requires multipart image upload');
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
