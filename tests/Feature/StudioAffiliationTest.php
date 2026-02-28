<?php

use App\Models\User;
use App\Models\Studio;
use App\Models\Artist;
use App\Enums\UserTypes;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

beforeEach(function () {
    // Create an artist user
    $this->artist = User::factory()->create([
        'type_id' => UserTypes::ARTIST_TYPE_ID,
    ]);

    // Create studios
    $this->studio1 = Studio::factory()->create(['name' => 'Studio One']);
    $this->studio2 = Studio::factory()->create(['name' => 'Studio Two']);
    $this->studio3 = Studio::factory()->create(['name' => 'Studio Three']);
});

describe('Leave Studio Affiliation', function () {
    test('artist can leave a studio they are affiliated with', function () {
        // Arrange: Add artist to studio
        $this->artist->affiliatedStudios()->attach($this->studio1->id, [
            'is_verified' => true,
            'is_primary' => true,
            'initiated_by' => 'artist',
        ]);

        // Act
        $response = $this->actingAs($this->artist)
            ->deleteJson("/api/artists/me/studio/{$this->studio1->id}");

        // Assert
        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Studio affiliation removed',
            ]);

        $this->assertDatabaseMissing('artists_studios', [
            'user_id' => $this->artist->id,
            'studio_id' => $this->studio1->id,
        ]);
    });

    test('artist cannot leave a studio they are not affiliated with', function () {
        $response = $this->actingAs($this->artist)
            ->deleteJson("/api/artists/me/studio/{$this->studio1->id}");

        $response->assertNotFound()
            ->assertJson([
                'error' => 'You are not affiliated with this studio',
            ]);
    });

    test('unauthenticated user cannot leave studio', function () {
        $response = $this->deleteJson("/api/artists/me/studio/{$this->studio1->id}");

        $response->assertUnauthorized();
    });
});

describe('Set Primary Studio', function () {
    test('artist can set a verified studio as primary', function () {
        // Arrange: Add artist to two studios
        $this->artist->affiliatedStudios()->attach($this->studio1->id, [
            'is_verified' => true,
            'is_primary' => true,
            'initiated_by' => 'artist',
        ]);
        $this->artist->affiliatedStudios()->attach($this->studio2->id, [
            'is_verified' => true,
            'is_primary' => false,
            'initiated_by' => 'studio',
        ]);

        // Act: Set studio2 as primary
        $response = $this->actingAs($this->artist)
            ->postJson("/api/artists/me/studio/{$this->studio2->id}/primary");

        // Assert
        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Primary studio updated',
            ]);

        // Verify studio2 is now primary
        $this->assertDatabaseHas('artists_studios', [
            'user_id' => $this->artist->id,
            'studio_id' => $this->studio2->id,
            'is_primary' => true,
        ]);

        // Verify studio1 is no longer primary
        $this->assertDatabaseHas('artists_studios', [
            'user_id' => $this->artist->id,
            'studio_id' => $this->studio1->id,
            'is_primary' => false,
        ]);
    });

    test('artist cannot set unverified studio as primary', function () {
        // Arrange: Add artist to studio but not verified
        $this->artist->affiliatedStudios()->attach($this->studio1->id, [
            'is_verified' => false,
            'is_primary' => false,
            'initiated_by' => 'studio',
        ]);

        // Act
        $response = $this->actingAs($this->artist)
            ->postJson("/api/artists/me/studio/{$this->studio1->id}/primary");

        // Assert
        $response->assertNotFound()
            ->assertJson([
                'error' => 'You are not verified at this studio',
            ]);
    });

    test('artist cannot set non-affiliated studio as primary', function () {
        $response = $this->actingAs($this->artist)
            ->postJson("/api/artists/me/studio/{$this->studio1->id}/primary");

        $response->assertNotFound();
    });
});

describe('Studio Invitations', function () {
    test('artist can view pending studio invitations', function () {
        // Arrange: Studio invites artist
        $this->artist->affiliatedStudios()->attach($this->studio1->id, [
            'is_verified' => false,
            'is_primary' => false,
            'initiated_by' => 'studio',
        ]);

        // Act
        $response = $this->actingAs($this->artist)
            ->getJson('/api/artists/me/studio-invitations');

        // Assert
        $response->assertOk()
            ->assertJsonStructure([
                'invitations' => [
                    '*' => ['id', 'name', 'slug'],
                ],
            ])
            ->assertJsonCount(1, 'invitations');
    });

    test('accepted invitations do not appear in pending list', function () {
        // Arrange: Verified affiliation should not show
        $this->artist->affiliatedStudios()->attach($this->studio1->id, [
            'is_verified' => true,
            'is_primary' => true,
            'initiated_by' => 'studio',
        ]);

        // Act
        $response = $this->actingAs($this->artist)
            ->getJson('/api/artists/me/studio-invitations');

        // Assert
        $response->assertOk()
            ->assertJsonCount(0, 'invitations');
    });

    test('artist-initiated requests do not appear as invitations', function () {
        // Arrange: Artist requested to join (not studio invitation)
        $this->artist->affiliatedStudios()->attach($this->studio1->id, [
            'is_verified' => false,
            'is_primary' => false,
            'initiated_by' => 'artist',
        ]);

        // Act
        $response = $this->actingAs($this->artist)
            ->getJson('/api/artists/me/studio-invitations');

        // Assert
        $response->assertOk()
            ->assertJsonCount(0, 'invitations');
    });

    test('artist can accept studio invitation', function () {
        // Arrange
        $this->artist->affiliatedStudios()->attach($this->studio1->id, [
            'is_verified' => false,
            'is_primary' => false,
            'initiated_by' => 'studio',
        ]);

        // Act
        $response = $this->actingAs($this->artist)
            ->postJson("/api/artists/me/studio-invitations/{$this->studio1->id}/accept");

        // Assert
        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseHas('artists_studios', [
            'user_id' => $this->artist->id,
            'studio_id' => $this->studio1->id,
            'is_verified' => true,
        ]);
    });

    test('artist can decline studio invitation', function () {
        // Arrange
        $this->artist->affiliatedStudios()->attach($this->studio1->id, [
            'is_verified' => false,
            'is_primary' => false,
            'initiated_by' => 'studio',
        ]);

        // Act
        $response = $this->actingAs($this->artist)
            ->postJson("/api/artists/me/studio-invitations/{$this->studio1->id}/decline");

        // Assert
        $response->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('artists_studios', [
            'user_id' => $this->artist->id,
            'studio_id' => $this->studio1->id,
        ]);
    });

    test('cannot accept non-existent invitation', function () {
        $response = $this->actingAs($this->artist)
            ->postJson("/api/artists/me/studio-invitations/{$this->studio1->id}/accept");

        $response->assertNotFound();
    });
});

describe('User Resource - Studios Affiliated', function () {
    test('user resource includes studios_affiliated array', function () {
        // Arrange: Add artist to multiple studios
        $this->artist->affiliatedStudios()->attach($this->studio1->id, [
            'is_verified' => true,
            'is_primary' => true,
            'initiated_by' => 'artist',
        ]);
        $this->artist->affiliatedStudios()->attach($this->studio2->id, [
            'is_verified' => true,
            'is_primary' => false,
            'initiated_by' => 'studio',
        ]);

        // Act
        $response = $this->actingAs($this->artist)
            ->getJson('/api/users/me');

        // Assert
        $response->assertOk();

        // Check for studios_affiliated (may or may not be wrapped in data)
        $studiosAffiliated = $response->json('data.studios_affiliated') ?? $response->json('studios_affiliated');
        expect($studiosAffiliated)->toBeArray();
        expect(count($studiosAffiliated))->toBe(2);

        // Verify primary studio is returned in 'studio' field
        $studio = $response->json('data.studio') ?? $response->json('studio');
        expect($studio['id'])->toBe($this->studio1->id);
    });

    test('studios_affiliated includes is_primary flag', function () {
        $this->artist->affiliatedStudios()->attach($this->studio1->id, [
            'is_verified' => true,
            'is_primary' => true,
            'initiated_by' => 'artist',
        ]);
        $this->artist->affiliatedStudios()->attach($this->studio2->id, [
            'is_verified' => true,
            'is_primary' => false,
            'initiated_by' => 'studio',
        ]);

        $response = $this->actingAs($this->artist)
            ->getJson('/api/users/me');

        $response->assertOk();

        $studiosAffiliated = $response->json('data.studios_affiliated') ?? $response->json('studios_affiliated');
        expect($studiosAffiliated)->toBeArray();

        $studio1Data = collect($studiosAffiliated)->firstWhere('id', $this->studio1->id);
        $studio2Data = collect($studiosAffiliated)->firstWhere('id', $this->studio2->id);

        expect($studio1Data['is_primary'])->toBeTrue();
        expect($studio2Data['is_primary'])->toBeFalse();
    });

    test('unverified studios are not included in studios_affiliated', function () {
        $this->artist->affiliatedStudios()->attach($this->studio1->id, [
            'is_verified' => true,
            'is_primary' => true,
            'initiated_by' => 'artist',
        ]);
        $this->artist->affiliatedStudios()->attach($this->studio2->id, [
            'is_verified' => false,
            'is_primary' => false,
            'initiated_by' => 'studio',
        ]);

        $response = $this->actingAs($this->artist)
            ->getJson('/api/users/me');

        $response->assertOk();

        $studiosAffiliated = $response->json('data.studios_affiliated') ?? $response->json('studios_affiliated');
        expect($studiosAffiliated)->toBeArray();
        expect(count($studiosAffiliated))->toBe(1);
    });
});

describe('Studio Artists Endpoint', function () {
    test('studio can view all affiliated artists with verification status', function () {
        // Arrange: Create studio owner
        $studioOwner = User::factory()->create([
            'type_id' => UserTypes::STUDIO_TYPE_ID,
        ]);
        $this->studio1->update(['owner_id' => $studioOwner->id]);

        // Add artists to studio
        $this->artist->affiliatedStudios()->attach($this->studio1->id, [
            'is_verified' => true,
            'is_primary' => true,
            'initiated_by' => 'artist',
        ]);

        $unverifiedArtist = User::factory()->create([
            'type_id' => UserTypes::ARTIST_TYPE_ID,
        ]);
        $unverifiedArtist->affiliatedStudios()->attach($this->studio1->id, [
            'is_verified' => false,
            'is_primary' => false,
            'initiated_by' => 'studio',
        ]);

        // Act
        $response = $this->actingAs($studioOwner)
            ->getJson("/api/studios/{$this->studio1->id}/artists");

        // Assert
        $response->assertOk()
            ->assertJsonCount(2, 'artists');

        $artists = $response->json('artists');
        $verifiedArtist = collect($artists)->firstWhere('id', $this->artist->id);
        $pendingArtist = collect($artists)->firstWhere('id', $unverifiedArtist->id);

        expect($verifiedArtist['is_verified'])->toBeTrue();
        expect($pendingArtist['is_verified'])->toBeFalse();
        expect($pendingArtist['initiated_by'])->toBe('studio');
    });
});
