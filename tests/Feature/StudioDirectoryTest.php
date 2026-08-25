<?php

use App\Enums\StudioPostStatus;
use App\Enums\StudioPostType;
use App\Models\Studio;
use App\Models\StudioPost;
use App\Models\User;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

function directoryPost(Studio $studio, array $attributes = []): StudioPost
{
    return StudioPost::create(array_merge([
        'studio_id' => $studio->id,
        'type' => StudioPostType::FlashDrop,
        'title' => 'Flash day',
        'slug' => 'flash-day',
        'content' => 'Body.',
        'status' => StudioPostStatus::Published,
        'published_at' => now()->subMinute(),
        'is_active' => true,
    ], $attributes));
}

test('the directory lists studios with their news pages', function () {
    $studio = Studio::factory()->create(['is_demo' => false, 'owner_id' => User::factory()->create()->id]);
    directoryPost($studio);

    $response = $this->getJson('/api/studios/directory')->assertOk();

    $entry = collect($response->json('studios'))->firstWhere('slug', $studio->slug);

    expect($entry)->not->toBeNull()
        ->and($entry['news'])->toHaveCount(1)
        ->and($entry['news'][0]['slug'])->toBe('flash-day');
});

test('demo studios are left out', function () {
    $demo = Studio::factory()->create(['is_demo' => true]);

    $slugs = collect($this->getJson('/api/studios/directory')->json('studios'))->pluck('slug');

    expect($slugs)->not->toContain($demo->slug);
});

test('ephemeral notices are not offered for indexing', function () {
    $studio = Studio::factory()->create(['is_demo' => false, 'owner_id' => User::factory()->create()->id]);
    directoryPost($studio, ['type' => StudioPostType::WalkIns, 'slug' => 'walk-ins']);

    $entry = collect($this->getJson('/api/studios/directory')->json('studios'))
        ->firstWhere('slug', $studio->slug);

    expect($entry['news'])->toBeEmpty();
});

test('drafts are not offered for indexing', function () {
    $studio = Studio::factory()->create(['is_demo' => false, 'owner_id' => User::factory()->create()->id]);
    directoryPost($studio, ['status' => StudioPostStatus::Draft]);

    $entry = collect($this->getJson('/api/studios/directory')->json('studios'))
        ->firstWhere('slug', $studio->slug);

    expect($entry['news'])->toBeEmpty();
});

test('the directory paginates', function () {
    Studio::factory()->count(3)->create(['is_demo' => false, 'owner_id' => User::factory()->create()->id]);

    $first = $this->getJson('/api/studios/directory?size=2&page=1')->assertOk();

    expect($first->json('studios'))->toHaveCount(2)
        ->and($first->json('has_more'))->toBeTrue();
});

test('the directory route is not swallowed by the studio wildcard', function () {
    $this->getJson('/api/studios/directory')
        ->assertOk()
        ->assertJsonStructure(['studios', 'has_more', 'total']);
});

test('unclaimed studios are left out of the directory', function () {
    $unclaimed = Studio::factory()->create(['is_demo' => false, 'owner_id' => null, 'is_claimed' => false]);

    $slugs = collect($this->getJson('/api/studios/directory')->json('studios'))->pluck('slug');

    expect($slugs)->not->toContain($unclaimed->slug);
});

test('a studio flagged claimed but with no owner is still left out', function () {
    $orphan = Studio::factory()->create(['is_demo' => false, 'owner_id' => null, 'is_claimed' => true]);

    $slugs = collect($this->getJson('/api/studios/directory')->json('studios'))->pluck('slug');

    expect($slugs)->not->toContain($orphan->slug);
});

test('guides are offered for indexing', function () {
    $studio = Studio::factory()->create(['is_demo' => false, 'owner_id' => User::factory()->create()->id]);

    StudioPost::create([
        'studio_id' => $studio->id,
        'type' => StudioPostType::Aftercare,
        'title' => 'Healing your new tattoo',
        'slug' => 'healing-your-new-tattoo',
        'content' => 'Keep it clean.',
        'status' => StudioPostStatus::Published,
        'published_at' => now()->subMinute(),
        'is_active' => true,
    ]);

    $entry = collect($this->getJson('/api/studios/directory')->json('studios'))
        ->firstWhere('slug', $studio->slug);

    expect($entry['guides'])->toHaveCount(1)
        ->and($entry['guides'][0]['slug'])->toBe('healing-your-new-tattoo')
        ->and($entry['news'])->toBeEmpty();
});

test('announcements and guides are kept apart', function () {
    $studio = Studio::factory()->create(['is_demo' => false, 'owner_id' => User::factory()->create()->id]);

    directoryPost($studio);
    StudioPost::create([
        'studio_id' => $studio->id,
        'type' => StudioPostType::Prep,
        'title' => 'Before your appointment',
        'slug' => 'before-your-appointment',
        'content' => 'Eat something.',
        'status' => StudioPostStatus::Published,
        'published_at' => now()->subMinute(),
        'is_active' => true,
    ]);

    $entry = collect($this->getJson('/api/studios/directory')->json('studios'))
        ->firstWhere('slug', $studio->slug);

    expect($entry['news'])->toHaveCount(1)
        ->and($entry['guides'])->toHaveCount(1);
});

test('a draft guide is not offered for indexing', function () {
    $studio = Studio::factory()->create(['is_demo' => false, 'owner_id' => User::factory()->create()->id]);

    StudioPost::create([
        'studio_id' => $studio->id,
        'type' => StudioPostType::Aftercare,
        'title' => 'Unfinished',
        'slug' => 'unfinished',
        'content' => 'Not ready.',
        'status' => StudioPostStatus::Draft,
        'is_active' => true,
    ]);

    $entry = collect($this->getJson('/api/studios/directory')->json('studios'))
        ->firstWhere('slug', $studio->slug);

    expect($entry['guides'])->toBeEmpty();
});
