<?php

use App\Enums\ArtistTattooApprovalStatus;
use App\Models\Image;
use App\Models\Style;
use App\Models\Studio;
use App\Models\Tag;
use App\Models\Tattoo;
use App\Models\User;

beforeEach(function () {
    $this->artist = User::factory()->asArtist()->create([
        'email_verified_at' => now(),
    ]);

    $this->client = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->studio = Studio::factory()->create();
    $this->studio->artists()->attach($this->artist->id, ['is_verified' => true]);

    $this->style = Style::factory()->create();
    $this->image = Image::factory()->create();

    $this->tattoo = Tattoo::factory()->create([
        'artist_id' => $this->artist->id,
        'studio_id' => $this->studio->id,
        'uploaded_by_user_id' => null,
        'approval_status' => ArtistTattooApprovalStatus::APPROVED,
        'is_visible' => true,
        'primary_image_id' => $this->image->id,
        'title' => 'Test Dragon Tattoo',
        'description' => 'A detailed dragon tattoo on the forearm',
        'placement' => 'forearm',
    ]);

    $this->tattoo->styles()->attach($this->style->id);
    $this->tattoo->images()->attach($this->image->id);
});

describe('Tattoo Detail (Story 6.1)', function () {

    it('GET /api/tattoos/{id} returns tattoo with core fields', function () {
        $response = $this->getJson("/api/tattoos/{$this->tattoo->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'tattoo' => [
                    'id',
                    'title',
                    'description',
                    'placement',
                    'primary_image',
                    'images',
                    'styles',
                    'tags',
                    'artist_id',
                    'studio',
                ],
            ]);

        $tattoo = $response->json('tattoo');
        expect($tattoo['id'])->toBe($this->tattoo->id);
        expect($tattoo['title'])->toBe('Test Dragon Tattoo');
        expect($tattoo['description'])->toBe('A detailed dragon tattoo on the forearm');

        exportFixture('tattoo-detail/show.json', $response->json());
    });

    it('GET /api/tattoos/{id} returns error for nonexistent tattoo', function () {
        $response = $this->getJson('/api/tattoos/999999');

        // Controller returns 400 due to legacy returnErrorResponse signature
        $response->assertStatus(400);
        $response->assertJsonStructure(['error', 'message']);

        exportFixture('tattoo-detail/not-found.json', $response->json());
    });

});

describe('Tattoo Detail - Uploader Attribution (Story 6.4)', function () {

    it('GET /api/tattoos/{id} includes uploader fields for client-uploaded tattoo', function () {
        $clientTattoo = Tattoo::factory()->create([
            'artist_id' => $this->artist->id,
            'uploaded_by_user_id' => $this->client->id,
            'approval_status' => ArtistTattooApprovalStatus::APPROVED,
            'is_visible' => true,
            'primary_image_id' => $this->image->id,
        ]);

        $response = $this->getJson("/api/tattoos/{$clientTattoo->id}");

        $response->assertOk();

        $tattoo = $response->json('tattoo');
        expect($tattoo['uploaded_by_user_id'])->toBe($this->client->id);
        expect($tattoo['approval_status'])->toBe('approved');

        exportFixture('tattoo-detail/client-uploaded.json', $response->json());
    });

});

describe('Tattoo Detail - Pending Status (Story 6.5)', function () {

    it('GET /api/tattoos/{id} returns pending tattoo via DB fallback', function () {
        $pendingTattoo = Tattoo::factory()->create([
            'artist_id' => $this->artist->id,
            'uploaded_by_user_id' => $this->client->id,
            'approval_status' => ArtistTattooApprovalStatus::PENDING,
            'is_visible' => false,
            'primary_image_id' => $this->image->id,
        ]);

        $response = $this->getJson("/api/tattoos/{$pendingTattoo->id}");

        $response->assertOk();

        $tattoo = $response->json('tattoo');
        expect($tattoo['approval_status'])->toBe('pending');

        exportFixture('tattoo-detail/pending.json', $response->json());
    });

});
