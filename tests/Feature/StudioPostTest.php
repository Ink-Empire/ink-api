<?php

use App\Enums\StudioPostStatus;
use App\Enums\StudioPostType;
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

function makePost(Studio $studio, array $attributes = []): StudioPost
{
    return StudioPost::create(array_merge([
        'studio_id' => $studio->id,
        'type' => StudioPostType::General,
        'title' => 'A post',
        'content' => 'Body text.',
        'status' => StudioPostStatus::Published,
        'published_at' => now()->subMinute(),
        'is_active' => true,
    ], $attributes));
}

describe('Type families', function () {
    test('announcements and guides are separated by type', function () {
        makePost($this->studio, ['type' => StudioPostType::FlashDrop, 'title' => 'Flash', 'slug' => 'flash']);
        makePost($this->studio, ['type' => StudioPostType::Aftercare, 'title' => 'Aftercare', 'slug' => 'aftercare']);

        expect($this->studio->announcements()->count())->toBe(1)
            ->and($this->studio->guides()->count())->toBe(1)
            ->and($this->studio->posts()->count())->toBe(2);
    });

    test('ephemeral notices do not get a page of their own', function () {
        expect(StudioPostType::WalkIns->hasPublicPage())->toBeFalse()
            ->and(StudioPostType::General->hasPublicPage())->toBeFalse()
            ->and(StudioPostType::FlashDrop->hasPublicPage())->toBeTrue()
            ->and(StudioPostType::Aftercare->hasPublicPage())->toBeTrue();
    });
});

describe('Visibility', function () {
    test('a draft is not visible', function () {
        makePost($this->studio, ['status' => StudioPostStatus::Draft, 'slug' => 'draft']);

        expect($this->studio->activeAnnouncements()->count())->toBe(0);
    });

    test('a post published in the future is not visible yet', function () {
        makePost($this->studio, ['published_at' => now()->addDay(), 'slug' => 'later']);

        expect($this->studio->activeAnnouncements()->count())->toBe(0);
    });

    test('an expired announcement drops off the page', function () {
        makePost($this->studio, [
            'slug' => 'expired',
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDay(),
        ]);

        expect($this->studio->activeAnnouncements()->count())->toBe(0);
    });

    test('an announcement inside its window is visible', function () {
        makePost($this->studio, [
            'slug' => 'current',
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDay(),
        ]);

        expect($this->studio->activeAnnouncements()->count())->toBe(1);
    });

    test('is_active still hides a post, as the dashboard switch always did', function () {
        makePost($this->studio, ['is_active' => false, 'slug' => 'switched-off']);

        expect($this->studio->activeAnnouncements()->count())->toBe(0);
    });

    test('guides never appear among the page announcements', function () {
        makePost($this->studio, ['type' => StudioPostType::Aftercare, 'slug' => 'care']);

        expect($this->studio->activeAnnouncements()->count())->toBe(0);
    });
});

test('posts created through publish carry a slug unique to the studio', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'announcements' => [
                ['title' => 'Books open', 'content' => 'One.'],
                ['title' => 'Books open', 'content' => 'Two, same title.'],
            ],
        ])
        ->assertOk();

    $slugs = $this->studio->posts()->pluck('slug')->sort()->values()->all();

    expect($slugs)->toBe(['books-open', 'books-open-2']);
});
