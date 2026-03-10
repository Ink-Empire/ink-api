<?php

use App\Models\ArtistSettings;
use App\Models\Image;
use App\Models\Studio;
use App\Models\Style;
use App\Models\User;

beforeEach(function () {
    $this->styles = Style::factory()->count(3)->create();
    $this->studio = Studio::factory()->create();
    $this->image = Image::factory()->create();

    $this->artist = User::factory()->asArtist()->create([
        'email_verified_at' => now(),
        'name' => 'Test Artist',
        'about' => 'Specializing in traditional tattoos',
        'location' => 'Auckland, NZ',
        'image_id' => $this->image->id,
    ]);

    $this->studio->artists()->attach($this->artist->id, ['is_verified' => true]);
    $this->artist->styles()->attach($this->styles->pluck('id'));

    ArtistSettings::create([
        'artist_id' => $this->artist->id,
        'books_open' => true,
    ]);
});

describe('Artist Profile (Story 10.1)', function () {

    it('GET /api/artists/{id}?db=true returns artist with core fields', function () {
        $response = $this->getJson("/api/artists/{$this->artist->id}?db=true");

        $response->assertOk()
            ->assertJsonStructure([
                'artist' => [
                    'id',
                    'name',
                    'slug',
                    'about',
                    'location',
                    'image',
                    'styles',
                    'settings',
                    'username',
                ],
            ]);

        $artist = $response->json('artist');
        expect($artist['id'])->toBe($this->artist->id);
        expect($artist['name'])->toBe('Test Artist');
        expect($artist['about'])->toBe('Specializing in traditional tattoos');

        exportFixture('artist-profile/show.json', $response->json());
    });

    it('GET /api/artists/{id}?db=true includes public settings', function () {
        $response = $this->getJson("/api/artists/{$this->artist->id}?db=true");

        $response->assertOk();

        $settings = $response->json('artist.settings');
        expect($settings)->toHaveKey('books_open');
        expect($settings)->toHaveKey('accepts_walk_ins');
        expect($settings)->toHaveKey('accepts_consultations');
        expect($settings)->toHaveKey('accepts_appointments');

        exportFixture('artist-profile/settings.json', $response->json());
    });

    it('GET /api/artists/{id}?db=true includes styles', function () {
        $response = $this->getJson("/api/artists/{$this->artist->id}?db=true");

        $response->assertOk();

        $styles = $response->json('artist.styles');
        expect($styles)->toHaveCount(3);

        exportFixture('artist-profile/with-styles.json', $response->json());
    });

    it('GET /api/artists/{id}?db=true returns 404 for nonexistent artist', function () {
        $response = $this->getJson('/api/artists/999999?db=true');

        $response->assertStatus(404);
    });

    it('GET /api/artists/{id}?db=true hides private fields for unauthenticated user', function () {
        $response = $this->getJson("/api/artists/{$this->artist->id}?db=true");

        $response->assertOk();

        $artist = $response->json('artist');
        expect($artist)->not->toHaveKey('email');
        expect($artist)->not->toHaveKey('phone');
    });

    it('GET /api/artists/{slug}?db=true resolves by slug', function () {
        $response = $this->getJson("/api/artists/{$this->artist->slug}?db=true");

        $response->assertOk();
        expect($response->json('artist.id'))->toBe($this->artist->id);

        exportFixture('artist-profile/by-slug.json', $response->json());
    });

});
