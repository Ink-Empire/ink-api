<?php

use App\Models\Image;
use App\Models\Tattoo;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->artist = User::factory()->asArtist()->create([
        'email_verified_at' => now(),
    ]);

    $this->image = Image::factory()->create();

    $this->tattoo = Tattoo::factory()->create([
        'artist_id' => $this->artist->id,
        'primary_image_id' => $this->image->id,
    ]);
});

describe('Favorite Tattoo (Story 7.1)', function () {

    it('POST /api/users/favorites/tattoo saves a tattoo', function () {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/users/favorites/tattoo', [
            'ids' => $this->tattoo->id,
            'action' => 'add',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'action',
                'type',
                'ids',
            ]);

        expect($this->user->fresh()->tattoos->pluck('id'))->toContain($this->tattoo->id);

        exportFixture('favorites/save-tattoo.json', $response->json());
    });

    it('POST /api/users/favorites/tattoo unsaves a tattoo', function () {
        $this->user->tattoos()->attach($this->tattoo->id);

        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/users/favorites/tattoo', [
            'ids' => $this->tattoo->id,
            'action' => 'remove',
        ]);

        $response->assertOk();

        expect($this->user->fresh()->tattoos->pluck('id'))->not->toContain($this->tattoo->id);

        exportFixture('favorites/unsave-tattoo.json', $response->json());
    });

});

describe('Favorite Artist (Story 7.2)', function () {

    it('POST /api/users/favorites/artist saves an artist', function () {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/users/favorites/artist', [
            'ids' => $this->artist->id,
            'action' => 'add',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'action',
                'type',
                'ids',
            ]);

        expect($this->user->fresh()->artists->pluck('id'))->toContain($this->artist->id);

        exportFixture('favorites/save-artist.json', $response->json());
    });

    it('POST /api/users/favorites/artist unsaves an artist', function () {
        $this->user->artists()->attach($this->artist->id);

        $this->actingAs($this->user, 'sanctum');

        $response = $this->postJson('/api/users/favorites/artist', [
            'ids' => $this->artist->id,
            'action' => 'remove',
        ]);

        $response->assertOk();

        expect($this->user->fresh()->artists->pluck('id'))->not->toContain($this->artist->id);

        exportFixture('favorites/unsave-artist.json', $response->json());
    });

});

describe('Favorite Auth', function () {

    it('POST /api/users/favorites/tattoo returns 401 for unauthenticated user', function () {
        $response = $this->postJson('/api/users/favorites/tattoo', [
            'ids' => $this->tattoo->id,
            'action' => 'add',
        ]);

        $response->assertStatus(401);
    });

});
