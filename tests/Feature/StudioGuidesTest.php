<?php

use App\Enums\StudioPostStatus;
use App\Enums\StudioPostType;
use App\Enums\UserTypes;
use App\Models\Conversation;
use App\Models\Studio;
use App\Models\StudioPost;
use App\Models\User;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create(['type_id' => UserTypes::STUDIO_TYPE_ID]);
    $this->studio = Studio::factory()->create(['owner_id' => $this->owner->id]);
    $this->client = User::factory()->create(['type_id' => UserTypes::CLIENT_TYPE_ID]);
});

/** Conversations are created through the API, as there is no factory for them. */
function startConversationWith(User $initiator, User $recipient): Conversation
{
    test()->actingAs($initiator, 'sanctum')
        ->postJson('/api/conversations', ['participant_id' => $recipient->id, 'type' => 'booking'])
        ->assertSuccessful();

    return Conversation::latest('id')->first();
}

function guide(Studio $studio, array $attributes = []): StudioPost
{
    return StudioPost::create(array_merge([
        'studio_id' => $studio->id,
        'type' => StudioPostType::Aftercare,
        'title' => 'Healing your new tattoo',
        'slug' => 'healing-your-new-tattoo',
        'content' => 'Keep it clean and out of the sun.',
        'status' => StudioPostStatus::Published,
        'published_at' => now()->subMinute(),
        'is_active' => true,
    ], $attributes));
}

describe('Publishing guides', function () {
    test('guides publish alongside everything else', function () {
        $this->actingAs($this->owner)
            ->putJson("/api/studios/{$this->studio->id}/page", [
                'about' => 'Updated in the same publish.',
                'guides' => [[
                    'type' => StudioPostType::Aftercare->value,
                    'title' => 'Healing your new tattoo',
                    'excerpt' => 'What to do for the first two weeks.',
                    'content' => 'Keep it clean and out of the sun.',
                    'is_default' => true,
                ]],
            ])
            ->assertOk();

        $saved = $this->studio->guides()->first();

        expect($this->studio->fresh()->about)->toBe('Updated in the same publish.')
            ->and($saved->title)->toBe('Healing your new tattoo')
            ->and($saved->is_default)->toBeTrue()
            ->and($saved->slug)->toBe('healing-your-new-tattoo');
    });

    test('only one guide can be the default', function () {
        $this->actingAs($this->owner)
            ->putJson("/api/studios/{$this->studio->id}/page", [
                'guides' => [
                    ['title' => 'First', 'content' => 'One.', 'is_default' => true],
                    ['title' => 'Second', 'content' => 'Two.', 'is_default' => true],
                ],
            ])
            ->assertOk();

        expect($this->studio->guides()->where('is_default', true)->count())->toBe(1);
    });

    test('an announcement type is rejected for a guide', function () {
        $this->actingAs($this->owner)
            ->putJson("/api/studios/{$this->studio->id}/page", [
                'guides' => [['type' => 'flash_drop', 'title' => 'Wrong family', 'content' => 'No.']],
            ])
            ->assertStatus(422);
    });

    test('a general article publishes as a guide with its own page', function () {
        $this->actingAs($this->owner)
            ->putJson("/api/studios/{$this->studio->id}/page", [
                'guides' => [[
                    'type' => StudioPostType::Article->value,
                    'title' => 'Our booking policy',
                    'content' => 'Deposits are non-refundable.',
                ]],
            ])
            ->assertOk();

        $saved = $this->studio->guides()->first();

        expect($saved->type)->toBe(StudioPostType::Article)
            ->and($saved->slug)->toBe('our-booking-policy')
            ->and($saved->type->isGuide())->toBeTrue()
            ->and($saved->type->hasPublicPage())->toBeTrue();

        $this->getJson("/api/studios/{$this->studio->id}/guides/{$saved->slug}")
            ->assertOk()
            ->assertJsonPath('guide.type', StudioPostType::Article->value);
    });

    test('a general article cannot claim the slot an aftercare guide needs', function () {
        $this->actingAs($this->owner)
            ->putJson("/api/studios/{$this->studio->id}/page", [
                'guides' => [
                    [
                        'type' => StudioPostType::Article->value,
                        'title' => 'Our booking policy',
                        'content' => 'Deposits are non-refundable.',
                        'is_default' => true,
                    ],
                    [
                        'type' => StudioPostType::Aftercare->value,
                        'title' => 'Healing your new tattoo',
                        'content' => 'Keep it clean.',
                        'is_default' => true,
                    ],
                ],
            ])
            ->assertOk();

        $article = $this->studio->guides()->where('title', 'Our booking policy')->first();

        expect($article->is_default)->toBeFalse()
            ->and($this->studio->defaultAftercareGuide()->title)->toBe('Healing your new tattoo');
    });

    test('guides missing from the payload are removed', function () {
        $kept = guide($this->studio, ['title' => 'Keep', 'slug' => 'keep']);
        guide($this->studio, ['title' => 'Drop', 'slug' => 'drop']);

        $this->actingAs($this->owner)
            ->putJson("/api/studios/{$this->studio->id}/page", [
                'guides' => [['id' => $kept->id, 'title' => 'Keep', 'content' => 'Still here.']],
            ])
            ->assertOk();

        expect($this->studio->guides()->pluck('title')->all())->toBe(['Keep']);
    });

    test('publishing guides leaves announcements alone', function () {
        StudioPost::create([
            'studio_id' => $this->studio->id,
            'type' => StudioPostType::FlashDrop,
            'title' => 'Flash day',
            'slug' => 'flash-day',
            'content' => 'Noon.',
            'status' => StudioPostStatus::Published,
            'published_at' => now(),
            'is_active' => true,
        ]);

        $this->actingAs($this->owner)
            ->putJson("/api/studios/{$this->studio->id}/page", [
                'guides' => [['title' => 'Aftercare', 'content' => 'Clean it.']],
            ])
            ->assertOk();

        expect($this->studio->announcements()->count())->toBe(1)
            ->and($this->studio->guides()->count())->toBe(1);
    });
});

describe('Reading guides', function () {
    test('the guides list is public', function () {
        guide($this->studio);

        $this->getJson("/api/studios/{$this->studio->slug}/guides")
            ->assertOk()
            ->assertJsonCount(1, 'guides')
            ->assertJsonPath('guides.0.title', 'Healing your new tattoo');
    });

    test('a guide is readable at its own url', function () {
        guide($this->studio);

        $this->getJson("/api/studios/{$this->studio->slug}/guides/healing-your-new-tattoo")
            ->assertOk()
            ->assertJsonPath('guide.type', StudioPostType::Aftercare->value);
    });

    test('a draft guide is not public', function () {
        guide($this->studio, ['status' => StudioPostStatus::Draft]);

        $this->getJson("/api/studios/{$this->studio->slug}/guides/healing-your-new-tattoo")
            ->assertNotFound();
    });

    test('an announcement is not reachable as a guide', function () {
        StudioPost::create([
            'studio_id' => $this->studio->id,
            'type' => StudioPostType::FlashDrop,
            'title' => 'Flash day',
            'slug' => 'flash-day',
            'content' => 'Noon.',
            'status' => StudioPostStatus::Published,
            'published_at' => now(),
            'is_active' => true,
        ]);

        $this->getJson("/api/studios/{$this->studio->slug}/guides/flash-day")->assertNotFound();
    });
});

describe('Sending aftercare', function () {
    test('the studio aftercare guide is sent into a conversation', function () {
        $sent = guide($this->studio, ['is_default' => true]);
        $conversation = startConversationWith($this->client, $this->owner);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/conversations/{$conversation->id}/messages/aftercare")
            ->assertStatus(201);

        expect($response->json('message.type'))->toBe('aftercare')
            ->and($response->json('message.metadata.guide_id'))->toBe($sent->id)
            ->and($response->json('message.metadata.url'))
            ->toBe("/studios/{$this->studio->slug}/guides/healing-your-new-tattoo");
    });

    test('the flagged default wins over a newer guide', function () {
        $flagged = guide($this->studio, ['is_default' => true, 'slug' => 'flagged', 'title' => 'Flagged']);
        guide($this->studio, ['slug' => 'newer', 'title' => 'Newer', 'published_at' => now()]);

        expect($this->studio->defaultAftercareGuide()->id)->toBe($flagged->id);
    });

    test('with no flag the most recent aftercare guide stands in', function () {
        guide($this->studio, ['slug' => 'older', 'title' => 'Older', 'published_at' => now()->subWeek()]);
        $newer = guide($this->studio, ['slug' => 'newer', 'title' => 'Newer', 'published_at' => now()]);

        expect($this->studio->defaultAftercareGuide()->id)->toBe($newer->id);
    });

    test('a studio with no guide is told to write one', function () {
        $conversation = startConversationWith($this->client, $this->owner);

        $this->actingAs($this->owner)
            ->postJson("/api/conversations/{$conversation->id}/messages/aftercare")
            ->assertStatus(422)
            ->assertJsonPath('error', 'No aftercare guide yet');
    });
});

test('a guide links under guides, not news', function () {
    guide($this->studio);

    $this->getJson("/api/studios/{$this->studio->slug}/guides")
        ->assertOk()
        ->assertJsonPath('guides.0.url', "/studios/{$this->studio->slug}/guides/healing-your-new-tattoo");
});

test('a guide is not reachable under the news segment', function () {
    guide($this->studio);

    $this->getJson("/api/studios/{$this->studio->slug}/news/healing-your-new-tattoo")
        ->assertNotFound();
});
