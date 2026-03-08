<?php

use App\Enums\ArtistTattooApprovalStatus;
use App\Models\Image;
use App\Models\Tattoo;
use App\Models\User;

beforeEach(function () {
    $this->artist = User::factory()->asArtist()->create([
        'email_verified_at' => now(),
    ]);

    $this->client = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->unrelatedUser = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->image = Image::factory()->create();

    $this->artistTattoo = Tattoo::factory()->create([
        'artist_id' => $this->artist->id,
        'uploaded_by_user_id' => null,
        'approval_status' => ArtistTattooApprovalStatus::APPROVED,
        'is_visible' => true,
        'primary_image_id' => $this->image->id,
    ]);

    $this->clientTattoo = Tattoo::factory()->create([
        'artist_id' => null,
        'uploaded_by_user_id' => $this->client->id,
        'approval_status' => ArtistTattooApprovalStatus::USER_ONLY,
        'is_visible' => false,
        'primary_image_id' => Image::factory()->create()->id,
    ]);
});

describe('Tattoo Delete Permissions (Stories 5.1, 5.2)', function () {

    it('DELETE /api/tattoos/{id} allows artist to delete own tattoo', function () {
        $tattooId = $this->artistTattoo->id;

        $this->actingAs($this->artist, 'sanctum');

        $response = $this->deleteJson("/api/tattoos/{$tattooId}");

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('tattoos', ['id' => $tattooId]);

        exportFixture('tattoo-permissions/delete-by-artist.json', $response->json());
    });

    it('DELETE /api/tattoos/{id} allows client to delete uploaded tattoo', function () {
        $tattooId = $this->clientTattoo->id;

        $this->actingAs($this->client, 'sanctum');

        $response = $this->deleteJson("/api/tattoos/{$tattooId}");

        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('tattoos', ['id' => $tattooId]);

        exportFixture('tattoo-permissions/delete-by-uploader.json', $response->json());
    });

    it('DELETE /api/tattoos/{id} returns 403 for non-owner', function () {
        $this->actingAs($this->unrelatedUser, 'sanctum');

        $response = $this->deleteJson("/api/tattoos/{$this->artistTattoo->id}");

        $response->assertStatus(403);

        $this->assertDatabaseHas('tattoos', ['id' => $this->artistTattoo->id]);

        exportFixture('tattoo-permissions/delete-forbidden.json', $response->json());
    });

    it('DELETE /api/tattoos/{id} returns 401 for unauthenticated user', function () {
        $response = $this->deleteJson("/api/tattoos/{$this->artistTattoo->id}");

        $response->assertStatus(401);

        exportFixture('tattoo-permissions/delete-unauthenticated.json', $response->json());
    });

});

describe('Tattoo Edit Permissions (Stories 4.2, 4.3)', function () {

    it('PUT /api/tattoos/{id} allows artist to update own tattoo', function () {
        $this->actingAs($this->artist, 'sanctum');

        $response = $this->putJson("/api/tattoos/{$this->artistTattoo->id}", [
            'title' => 'Updated Title',
            'description' => 'Updated description',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'tattoo' => ['id', 'title', 'description'],
            ]);

        $this->artistTattoo->refresh();
        expect($this->artistTattoo->title)->toBe('Updated Title');
        expect($this->artistTattoo->description)->toBe('Updated description');

        exportFixture('tattoo-permissions/edit-by-artist.json', $response->json());
    });

    it('PUT /api/tattoos/{id} allows client to update uploaded tattoo', function () {
        $this->actingAs($this->client, 'sanctum');

        $response = $this->putJson("/api/tattoos/{$this->clientTattoo->id}", [
            'title' => 'Client Updated Title',
            'description' => 'Client updated description',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'tattoo' => ['id', 'title', 'description'],
            ]);

        $this->clientTattoo->refresh();
        expect($this->clientTattoo->title)->toBe('Client Updated Title');
        expect($this->clientTattoo->description)->toBe('Client updated description');

        exportFixture('tattoo-permissions/edit-by-uploader.json', $response->json());
    });

    it('PUT /api/tattoos/{id} returns 403 for non-owner', function () {
        $this->actingAs($this->unrelatedUser, 'sanctum');

        $response = $this->putJson("/api/tattoos/{$this->artistTattoo->id}", [
            'title' => 'Hacked Title',
        ]);

        $response->assertStatus(403);

        $this->artistTattoo->refresh();
        expect($this->artistTattoo->title)->not->toBe('Hacked Title');

        exportFixture('tattoo-permissions/edit-forbidden.json', $response->json());
    });

    it('PUT /api/tattoos/{id} returns 401 for unauthenticated user', function () {
        $response = $this->putJson("/api/tattoos/{$this->artistTattoo->id}", [
            'title' => 'Hacked Title',
        ]);

        $response->assertStatus(401);

        exportFixture('tattoo-permissions/edit-unauthenticated.json', $response->json());
    });

});
