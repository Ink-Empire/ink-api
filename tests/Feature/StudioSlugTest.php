<?php

use App\Models\Studio;
use App\Models\User;
use App\Services\StudioService;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

beforeEach(function () {
    $this->service = app(StudioService::class);
});

describe('Slug generation', function () {
    test('it builds a url safe slug from a studio name', function () {
        expect($this->service->generateSlug('Blackwork & Co.'))->toBe('blackwork-co');
    });

    test('it suffixes a slug that another studio already holds', function () {
        Studio::factory()->create(['name' => 'Reid Studios', 'slug' => 'reid-studios']);

        expect($this->service->generateSlug('Reid Studios'))->toBe('reid-studios-2');
    });

    test('it keeps counting past an occupied suffix', function () {
        Studio::factory()->create(['slug' => 'reid-studios']);
        Studio::factory()->create(['slug' => 'reid-studios-2']);

        expect($this->service->generateSlug('Reid Studios'))->toBe('reid-studios-3');
    });

    test('it lets a studio keep its own slug', function () {
        $studio = Studio::factory()->create(['name' => 'Reid Studios', 'slug' => 'reid-studios']);

        expect($this->service->generateSlug('Reid Studios', $studio->id))->toBe('reid-studios');
    });

    test('it falls back when a name has no slug safe characters', function () {
        expect($this->service->generateSlug('!!!'))->toBe('studio');
    });
});

describe('Slug uniqueness on write', function () {
    test('it rejects an update that takes another studio slug', function () {
        $owner = User::factory()->create();
        $taken = Studio::factory()->create(['slug' => 'taken-slug']);
        $studio = Studio::factory()->create(['owner_id' => $owner->id]);

        $response = $this->actingAs($owner)
            ->putJson("/api/studios/studio/{$studio->id}", ['slug' => 'taken-slug']);

        $response->assertStatus(422);

        expect($studio->fresh()->slug)->not->toBe($taken->slug);
    });

    test('it normalizes a slug supplied on update', function () {
        $owner = User::factory()->create();
        $studio = Studio::factory()->create(['owner_id' => $owner->id]);

        $this->actingAs($owner)
            ->putJson("/api/studios/studio/{$studio->id}", ['slug' => 'Reid  Studios!'])
            ->assertOk();

        expect($studio->fresh()->slug)->toBe('reid-studios');
    });

    test('it leaves the existing slug alone when an empty one is sent', function () {
        $owner = User::factory()->create();
        $studio = Studio::factory()->create(['owner_id' => $owner->id, 'slug' => 'keep-me']);

        $this->actingAs($owner)
            ->putJson("/api/studios/studio/{$studio->id}", ['slug' => ''])
            ->assertOk();

        expect($studio->fresh()->slug)->toBe('keep-me');
    });
});
