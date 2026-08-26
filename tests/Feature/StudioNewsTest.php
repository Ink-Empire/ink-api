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

function newsPost(Studio $studio, array $attributes = []): StudioPost
{
    return StudioPost::create(array_merge([
        'studio_id' => $studio->id,
        'type' => StudioPostType::FlashDrop,
        'title' => 'Flash day',
        'slug' => 'flash-day',
        'content' => 'Walk-in flash from noon.',
        'status' => StudioPostStatus::Published,
        'published_at' => now()->subMinute(),
        'is_active' => true,
    ], $attributes));
}

describe('Publishing with types and dates', function () {
    test('an announcement can be published with a type and a date window', function () {
        $this->actingAs($this->owner)
            ->putJson("/api/studios/{$this->studio->id}/page", [
                'announcements' => [[
                    'type' => StudioPostType::GuestSpot->value,
                    'title' => 'Guest artist next week',
                    'content' => 'Limited slots.',
                    'starts_at' => now()->addDay()->toDateString(),
                    'ends_at' => now()->addWeek()->toDateString(),
                ]],
            ])
            ->assertOk();

        $post = $this->studio->posts()->first();

        expect($post->type)->toBe(StudioPostType::GuestSpot)
            ->and($post->starts_at)->not->toBeNull()
            ->and($post->ends_at)->not->toBeNull();
    });

    test('an end date before the start date is rejected', function () {
        $this->actingAs($this->owner)
            ->putJson("/api/studios/{$this->studio->id}/page", [
                'announcements' => [[
                    'title' => 'Backwards',
                    'content' => 'Nope.',
                    'starts_at' => now()->addWeek()->toDateString(),
                    'ends_at' => now()->addDay()->toDateString(),
                ]],
            ])
            ->assertStatus(422);

        expect($this->studio->posts()->count())->toBe(0);
    });

    test('an unknown announcement type is rejected', function () {
        $this->actingAs($this->owner)
            ->putJson("/api/studios/{$this->studio->id}/page", [
                'announcements' => [[
                    'type' => 'aftercare',
                    'title' => 'Guides are not announcements',
                    'content' => 'Wrong family.',
                ]],
            ])
            ->assertStatus(422);
    });

    test('an existing announcement keeps its row when its type changes', function () {
        $post = newsPost($this->studio, ['type' => StudioPostType::General]);

        $this->actingAs($this->owner)
            ->putJson("/api/studios/{$this->studio->id}/page", [
                'announcements' => [[
                    'id' => $post->id,
                    'type' => StudioPostType::BooksOpen->value,
                    'title' => 'Flash day',
                    'content' => 'Walk-in flash from noon.',
                ]],
            ])
            ->assertOk();

        expect($this->studio->posts()->count())->toBe(1)
            ->and($post->fresh()->type)->toBe(StudioPostType::BooksOpen);
    });
});

describe('Public news pages', function () {
    test('a substantive announcement is readable at its own url', function () {
        newsPost($this->studio);

        $this->getJson("/api/studios/{$this->studio->slug}/news/flash-day")
            ->assertOk()
            ->assertJsonPath('post.title', 'Flash day')
            ->assertJsonPath('post.type', StudioPostType::FlashDrop->value)
            ->assertJsonPath('post.url', "/studios/{$this->studio->slug}/news/flash-day");
    });

    test('an ephemeral notice has no page of its own', function () {
        newsPost($this->studio, ['type' => StudioPostType::WalkIns, 'slug' => 'walk-ins-today']);

        $this->getJson("/api/studios/{$this->studio->slug}/news/walk-ins-today")
            ->assertNotFound();
    });

    test('an expired announcement stays readable as an archive', function () {
        newsPost($this->studio, [
            'starts_at' => now()->subWeeks(2),
            'ends_at' => now()->subWeek(),
        ]);

        // Gone from the studio page...
        expect($this->studio->activeAnnouncements()->count())->toBe(0);

        // ...but its page is still there.
        $this->getJson("/api/studios/{$this->studio->slug}/news/flash-day")->assertOk();
    });

    test('a draft has no public page', function () {
        newsPost($this->studio, ['status' => StudioPostStatus::Draft]);

        $this->getJson("/api/studios/{$this->studio->slug}/news/flash-day")->assertNotFound();
    });

    test('an unknown slug is a 404', function () {
        $this->getJson("/api/studios/{$this->studio->slug}/news/nothing-here")->assertNotFound();
    });

    test('the studio page carries a url for announcements that have one', function () {
        newsPost($this->studio);

        $this->getJson("/api/studios/{$this->studio->slug}")
            ->assertOk()
            ->assertJsonPath('studio.announcements.0.url', "/studios/{$this->studio->slug}/news/flash-day")
            ->assertJsonPath('studio.announcements.0.type_label', 'Flash drop');
    });
});
