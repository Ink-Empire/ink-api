<?php

use App\Models\Artist;
use App\Models\ArtistSettings;
use App\Models\ArtistWishlist;
use App\Models\Image;
use App\Models\Studio;
use App\Models\Style;
use App\Models\User;

use Laravel\Sanctum\Sanctum;



beforeEach(function () {
    // Create test styles
    $this->styles = Style::factory()->count(5)->create();

    // Create a test studio
    $this->studio = Studio::factory()->create();

    // Create test image
    $this->image = Image::factory()->create();

    // Create test artists with full relationships
    $this->artists = Artist::factory()
        ->count(3)
        ->create([
            'image_id' => $this->image->id,
        ])
        ->each(function ($artist) {
            // Associate artist with studio via pivot table
            $this->studio->artists()->attach($artist->id, ['is_verified' => true]);
            // Add settings
            ArtistSettings::create([
                'artist_id' => $artist->id,
                'books_open' => fake()->boolean(),
            ]);
            // Add styles
            $artist->styles()->attach($this->styles->random(2)->pluck('id'));
        });

    // Create the test client user
    $this->user = User::factory()->create();
    $this->user->styles()->attach($this->styles->random(2)->pluck('id'));
});

describe('Client Dashboard API Contracts', function () {

    it('GET /api/client/dashboard returns correct structure', function () {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson('/api/client/dashboard');

        $response->assertOk()
            ->assertJsonStructure([
                'appointments',
                'conversations',
                'wishlist_count',
                'suggested_artists',
            ]);

        // Verify suggested_artists structure when present
        $data = $response->json();
        if (count($data['suggested_artists']) > 0) {
            $response->assertJsonStructure([
                'suggested_artists' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'username',
                        'books_open',
                        'is_demo',
                    ]
                ]
            ]);
        }

        exportFixture('client/dashboard.json', $response->json());
    });

    it('GET /api/client/favorites returns correct structure', function () {
        // Add some artists to favorites
        $this->user->artists()->attach($this->artists->pluck('id'));

        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson('/api/client/favorites');

        $response->assertOk()
            ->assertJsonStructure([
                'favorites' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'username',
                        'books_open',
                    ]
                ]
            ]);

        // Verify nested structures when present
        $data = $response->json();
        if (count($data['favorites']) > 0) {
            $favorite = $data['favorites'][0];

            // Verify slug is present (required for URLs)
            expect($favorite)->toHaveKey('slug');
            expect($favorite['slug'])->not->toBeNull();

            // Verify username is present (required for @mentions)
            expect($favorite)->toHaveKey('username');
        }

        exportFixture('client/favorites.json', $response->json());
    });

    it('GET /api/client/wishlist returns correct structure', function () {
        // Add artist to wishlist
        ArtistWishlist::create([
            'user_id' => $this->user->id,
            'artist_id' => $this->artists->first()->id,
            'notify_booking_open' => true,
        ]);

        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson('/api/client/wishlist');

        $response->assertOk()
            ->assertJsonStructure([
                'wishlist' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'username',
                        'books_open',
                        'notify_booking_open',
                        'notified_at',
                        'added_at',
                    ]
                ]
            ]);

        exportFixture('client/wishlist.json', $response->json());
    });

    it('GET /api/client/suggested-artists returns correct structure', function () {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson('/api/client/suggested-artists?limit=6');

        $response->assertOk()
            ->assertJsonStructure([
                'artists',
            ]);

        $data = $response->json();
        if (count($data['artists']) > 0) {
            $response->assertJsonStructure([
                'artists' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'username',
                        'books_open',
                        'is_demo',
                    ]
                ]
            ]);
        }

        exportFixture('client/suggested-artists.json', $response->json());
    });

});

describe('Client Dashboard Mutations', function () {

    it('POST /api/users/favorites/artist toggles favorite correctly', function () {
        $artist = $this->artists->first();

        $this->actingAs($this->user, 'sanctum');

        // Add to favorites
        $response = $this->postJson('/api/users/favorites/artist', [
            'ids' => $artist->id,
            'action' => 'add',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'action',
                'type',
                'ids',
            ]);

        expect($this->user->fresh()->artists->pluck('id'))->toContain($artist->id);

        // Remove from favorites
        $response = $this->postJson('/api/users/favorites/artist', [
            'ids' => $artist->id,
            'action' => 'remove',
        ]);

        $response->assertOk();
        expect($this->user->fresh()->artists->pluck('id'))->not->toContain($artist->id);
    });

    it('POST /api/client/wishlist adds to wishlist correctly', function () {
        $artist = $this->artists->first();

        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/client/wishlist', [
            'artist_id' => $artist->id,
            'notify_booking_open' => true,
        ]);

        $response->assertCreated()
            ->assertJson(['success' => true]);

        expect(
            ArtistWishlist::where('user_id', $this->user->id)
                ->where('artist_id', $artist->id)
                ->exists()
        )->toBeTrue();
    });

    it('DELETE /api/client/wishlist/{id} removes from wishlist', function () {
        $artist = $this->artists->first();

        ArtistWishlist::create([
            'user_id' => $this->user->id,
            'artist_id' => $artist->id,
        ]);

        $this->actingAs($this->user, 'sanctum');

        $response = $this->deleteJson("/api/client/wishlist/{$artist->id}");

        $response->assertOk()
            ->assertJson(['success' => true]);

        expect(
            ArtistWishlist::where('user_id', $this->user->id)
                ->where('artist_id', $artist->id)
                ->exists()
        )->toBeFalse();
    });

});
