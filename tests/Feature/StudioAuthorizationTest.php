<?php

use App\Enums\UserTypes;
use App\Models\Studio;
use App\Models\StudioPost;
use App\Models\User;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create(['type_id' => UserTypes::STUDIO_TYPE_ID]);
    $this->stranger = User::factory()->create(['type_id' => UserTypes::ARTIST_TYPE_ID]);
    $this->studio = Studio::factory()->create(['owner_id' => $this->owner->id]);
});

describe('Studio details', function () {
    test('the owner can update their studio', function () {
        $this->actingAs($this->owner)
            ->putJson("/api/studios/studio/{$this->studio->id}", ['about' => 'updated by the owner'])
            ->assertOk();

        expect($this->studio->fresh()->about)->toBe('updated by the owner');
    });

    test('a stranger cannot update someone elses studio', function () {
        $before = $this->studio->about;

        $this->actingAs($this->stranger)
            ->putJson("/api/studios/studio/{$this->studio->id}", ['about' => 'edited by a stranger'])
            ->assertForbidden();

        expect($this->studio->fresh()->about)->toBe($before);
    });

    test('an artist verified at the studio still cannot update it', function () {
        $this->stranger->affiliatedStudios()->attach($this->studio->id, [
            'is_verified' => true,
            'initiated_by' => 'studio',
        ]);
        $before = $this->studio->about;

        $this->actingAs($this->stranger)
            ->putJson("/api/studios/studio/{$this->studio->id}", ['about' => 'edited by an artist'])
            ->assertForbidden();

        expect($this->studio->fresh()->about)->toBe($before);
    });

    test('updating an unknown studio is a 404 rather than a server error', function () {
        $this->actingAs($this->owner)
            ->putJson('/api/studios/studio/99999999', ['about' => 'nobody'])
            ->assertNotFound();
    });
});

describe('Announcements', function () {
    test('the owner can post an announcement', function () {
        $this->actingAs($this->owner)
            ->postJson("/api/studios/{$this->studio->id}/announcements", [
                'title' => 'Books open',
                'content' => 'March slots are live',
            ])
            ->assertOk();

        expect($this->studio->announcements()->count())->toBe(1);
    });

    test('a stranger cannot post an announcement to someone elses studio', function () {
        $this->actingAs($this->stranger)
            ->postJson("/api/studios/{$this->studio->id}/announcements", [
                'title' => 'Posted by a stranger',
                'content' => 'Not my studio',
            ])
            ->assertForbidden();

        expect($this->studio->announcements()->count())->toBe(0);
    });

    test('a stranger cannot edit an announcement on someone elses studio', function () {
        $announcement = StudioPost::create([
            'studio_id' => $this->studio->id,
            'title' => 'Original',
            'content' => 'Original content',
        ]);

        $this->actingAs($this->stranger)
            ->putJson("/api/studios/{$this->studio->id}/announcements/{$announcement->id}", [
                'title' => 'Hijacked',
            ])
            ->assertForbidden();

        expect($announcement->fresh()->title)->toBe('Original');
    });

    test('a stranger cannot delete an announcement on someone elses studio', function () {
        $announcement = StudioPost::create([
            'studio_id' => $this->studio->id,
            'title' => 'Keep me',
            'content' => 'Still here',
        ]);

        $this->actingAs($this->stranger)
            ->deleteJson("/api/studios/{$this->studio->id}/announcements/{$announcement->id}")
            ->assertForbidden();

        expect(StudioPost::find($announcement->id))->not->toBeNull();
    });
});

describe('Roster and spotlights', function () {
    test('a stranger cannot remove an artist from someone elses studio', function () {
        $artist = User::factory()->create(['type_id' => UserTypes::ARTIST_TYPE_ID]);
        $this->studio->artists()->attach($artist->id, ['is_verified' => true, 'initiated_by' => 'studio']);

        $this->actingAs($this->stranger)
            ->deleteJson("/api/studios/{$this->studio->id}/artists/{$artist->id}")
            ->assertForbidden();

        expect($this->studio->artists()->count())->toBe(1);
    });

    test('a stranger cannot add a spotlight to someone elses studio', function () {
        $artist = User::factory()->create(['type_id' => UserTypes::ARTIST_TYPE_ID]);

        $this->actingAs($this->stranger)
            ->postJson("/api/studios/{$this->studio->id}/spotlights", [
                'type' => 'artist',
                'item_id' => $artist->id,
            ])
            ->assertForbidden();

        expect($this->studio->spotlights()->count())->toBe(0);
    });
});

describe('Studio dashboard', function () {
    test('the owner can read their studio dashboard', function () {
        $this->actingAs($this->owner)
            ->getJson("/api/studios/{$this->studio->id}/dashboard")
            ->assertOk();
    });

    test('a stranger cannot read someone elses studio dashboard', function () {
        $this->actingAs($this->stranger)
            ->getJson("/api/studios/{$this->studio->id}/dashboard")
            ->assertForbidden();
    });

    test('a stranger cannot read someone elses studio stats', function () {
        $this->actingAs($this->stranger)
            ->getJson("/api/studios/{$this->studio->id}/dashboard-stats")
            ->assertForbidden();
    });
});
