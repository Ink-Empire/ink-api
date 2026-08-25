<?php

use App\Enums\SpotlightType;
use App\Enums\UserTypes;
use App\Models\Studio;
use App\Models\StudioPost;
use App\Models\StudioAvailability;
use App\Models\StudioSpotlight;
use App\Models\User;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create(['type_id' => UserTypes::STUDIO_TYPE_ID]);
    $this->stranger = User::factory()->create(['type_id' => UserTypes::ARTIST_TYPE_ID]);
    $this->studio = Studio::factory()->create(['owner_id' => $this->owner->id, 'about' => 'before']);
    $this->artist = User::factory()->create(['type_id' => UserTypes::ARTIST_TYPE_ID]);
});

test('one request lands details, hours, announcements and spotlights together', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'name' => 'Published Name',
            'about' => 'after',
            'phone' => '555-0100',
            'city' => 'Louisville',
            'working_hours' => [
                ['day_of_week' => 1, 'start_time' => '10:00:00', 'end_time' => '18:00:00', 'is_day_off' => false],
            ],
            'announcements' => [
                ['title' => 'Books open', 'content' => 'March 1.'],
            ],
            'spotlights' => [
                ['type' => SpotlightType::Artist->value, 'item_id' => $this->artist->id],
            ],
        ])
        ->assertOk();

    $studio = $this->studio->fresh();

    expect($studio->name)->toBe('Published Name')
        ->and($studio->about)->toBe('after')
        ->and($studio->phone)->toBe('555-0100')
        ->and($studio->address->city)->toBe('Louisville')
        ->and(StudioAvailability::where('studio_id', $studio->id)->where('day_of_week', 1)->first()->start_time)->toBe('10:00:00')
        ->and($studio->announcements()->count())->toBe(1)
        ->and($studio->spotlights()->count())->toBe(1);
});

test('announcements missing from the payload are removed', function () {
    $kept = StudioPost::create([
        'studio_id' => $this->studio->id, 'title' => 'Keep', 'content' => 'Still here.', 'is_active' => true,
    ]);
    StudioPost::create([
        'studio_id' => $this->studio->id, 'title' => 'Drop', 'content' => 'Gone.', 'is_active' => true,
    ]);

    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'announcements' => [
                ['id' => $kept->id, 'title' => 'Keep', 'content' => 'Still here.'],
                ['title' => 'Brand new', 'content' => 'Added in the same publish.'],
            ],
        ])
        ->assertOk();

    $titles = $this->studio->announcements()->pluck('title')->sort()->values()->all();

    expect($titles)->toBe(['Brand new', 'Keep']);
});

test('spotlights are reconciled to the list sent', function () {
    $dropped = User::factory()->create(['type_id' => UserTypes::ARTIST_TYPE_ID]);

    StudioSpotlight::create([
        'studio_id' => $this->studio->id,
        'spotlightable_type' => SpotlightType::Artist->value,
        'spotlightable_id' => $dropped->id,
        'display_order' => 0,
    ]);

    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'spotlights' => [
                ['type' => SpotlightType::Artist->value, 'item_id' => $this->artist->id],
            ],
        ])
        ->assertOk();

    $pins = $this->studio->spotlights()->pluck('spotlightable_id')->all();

    expect($pins)->toBe([$this->artist->id]);
});

test('nothing is applied when part of the payload is invalid', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'about' => 'should not stick',
            'announcements' => [
                ['title' => 'Missing its content'],
            ],
        ])
        ->assertStatus(422);

    expect($this->studio->fresh()->about)->toBe('before');
});

test('a stranger cannot publish someone elses page', function () {
    $this->actingAs($this->stranger)
        ->putJson("/api/studios/{$this->studio->id}/page", ['about' => 'not mine'])
        ->assertForbidden();

    expect($this->studio->fresh()->about)->toBe('before');
});

test('an empty spotlight list clears them all', function () {
    StudioSpotlight::create([
        'studio_id' => $this->studio->id,
        'spotlightable_type' => SpotlightType::Artist->value,
        'spotlightable_id' => $this->artist->id,
        'display_order' => 0,
    ]);

    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", ['spotlights' => []])
        ->assertOk();

    expect($this->studio->spotlights()->count())->toBe(0);
});
