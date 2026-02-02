<?php

use App\Models\Artist;
use App\Models\Image;
use App\Models\Studio;
use App\Models\Style;
use App\Models\User;




beforeEach(function () {
    // Create test styles
    $this->styles = Style::factory()->count(5)->create();

    // Create test image
    $this->image = Image::factory()->create();

    // Create a client user
    $this->user = User::factory()->create([
        'image_id' => $this->image->id,
    ]);

    // Attach styles to user
    $this->user->styles()->attach($this->styles->random(2)->pluck('id'));

    // Create a test studio and artist for favorites testing
    $this->studio = Studio::factory()->create();
    $this->artist = Artist::factory()->create([
        'image_id' => $this->image->id,
    ]);

    // Associate artist with studio via pivot table
    $this->studio->artists()->attach($this->artist->id, ['is_verified' => true]);
});

describe('User Profile API Contracts', function () {

    it('GET /api/users/{id} returns correct structure for own profile', function () {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->getJson("/api/users/{$this->user->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'user' => [
                    'id',
                    'name',
                    'username',
                    'email',
                    'location',
                    'slug',
                    'type',
                ]
            ]);

        exportFixture('user/profile.json', $response->json());
    });

    it('PUT /api/users/{id} updates profile correctly', function () {
        $this->actingAs($this->user, 'sanctum');

        $response = $this->putJson("/api/users/{$this->user->id}", [
            'name' => 'Updated Name',
            'location' => 'New York, NY',
        ]);

        $response->assertOk();

        $this->user->refresh();
        expect($this->user->name)->toBe('Updated Name');
        expect($this->user->location)->toBe('New York, NY');

        exportFixture('user/update-response.json', $response->json());
    });

});
