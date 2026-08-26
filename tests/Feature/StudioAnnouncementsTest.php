<?php

use App\Enums\UserTypes;
use App\Models\Studio;
use App\Models\StudioPost;
use App\Models\User;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create(['type_id' => UserTypes::STUDIO_TYPE_ID]);
    $this->studio = Studio::factory()->create(['owner_id' => $this->owner->id]);
});

test('active announcements reach the public studio page', function () {
    StudioPost::create([
        'studio_id' => $this->studio->id,
        'title' => 'Books open March 1',
        'content' => 'Requests through the site only.',
        'is_active' => true,
    ]);

    $this->getJson("/api/studios/{$this->studio->slug}")
        ->assertOk()
        ->assertJsonCount(1, 'studio.announcements')
        ->assertJsonPath('studio.announcements.0.title', 'Books open March 1');
});

test('inactive announcements are not published', function () {
    StudioPost::create([
        'studio_id' => $this->studio->id,
        'title' => 'Draft, not for visitors',
        'content' => 'Should stay hidden.',
        'is_active' => false,
    ]);

    $this->getJson("/api/studios/{$this->studio->slug}")
        ->assertOk()
        ->assertJsonCount(0, 'studio.announcements');
});

test('a studio with no announcements returns an empty list', function () {
    $this->getJson("/api/studios/{$this->studio->slug}")
        ->assertOk()
        ->assertJsonCount(0, 'studio.announcements');
});

test('an unknown studio slug is a 404 rather than a server error', function () {
    $this->getJson('/api/studios/no-such-studio-anywhere')
        ->assertNotFound();
});

test('announcements come back newest first', function () {
    $older = StudioPost::create([
        'studio_id' => $this->studio->id,
        'title' => 'Posted first',
        'content' => 'The older one.',
        'is_active' => true,
    ]);
    $older->forceFill(['created_at' => now()->subDay()])->save();

    StudioPost::create([
        'studio_id' => $this->studio->id,
        'title' => 'Posted second',
        'content' => 'The newer one.',
        'is_active' => true,
    ]);

    $this->getJson("/api/studios/{$this->studio->slug}")
        ->assertOk()
        ->assertJsonPath('studio.announcements.0.title', 'Posted second')
        ->assertJsonPath('studio.announcements.1.title', 'Posted first');
});
