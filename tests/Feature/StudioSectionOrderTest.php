<?php

use App\Enums\StudioSection;
use App\Enums\UserTypes;
use App\Models\Studio;
use App\Models\User;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create(['type_id' => UserTypes::STUDIO_TYPE_ID]);
    $this->stranger = User::factory()->create(['type_id' => UserTypes::ARTIST_TYPE_ID]);
    $this->studio = Studio::factory()->create(['owner_id' => $this->owner->id]);
});

test('a studio that has never rearranged anything gets the default order', function () {
    $this->getJson("/api/studios/{$this->studio->slug}")
        ->assertOk()
        ->assertJsonPath('studio.section_order', StudioSection::values());
});

test('the owner can publish a new order', function () {
    $order = [
        StudioSection::Guides->value,
        StudioSection::Location->value,
        StudioSection::Hours->value,
        StudioSection::Contact->value,
        StudioSection::Spotlight->value,
        StudioSection::Artists->value,
    ];

    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", ['section_order' => $order])
        ->assertOk();

    $this->getJson("/api/studios/{$this->studio->slug}")
        ->assertOk()
        ->assertJsonPath('studio.section_order', $order);
});

test('a section missing from a saved order still appears, at the end', function () {
    // Stands in for a section added after this studio saved its arrangement.
    $this->studio->update(['section_order' => [StudioSection::Guides->value, StudioSection::Location->value]]);

    $resolved = $this->studio->fresh()->sectionOrder();

    expect($resolved[0])->toBe(StudioSection::Guides->value)
        ->and($resolved[1])->toBe(StudioSection::Location->value)
        ->and($resolved)->toHaveCount(count(StudioSection::values()))
        ->and(array_diff(StudioSection::values(), $resolved))->toBe([]);
});

test('an unknown section is rejected and nothing else applies', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'about' => 'should not stick',
            'section_order' => [StudioSection::Guides->value, 'newsletter'],
        ])
        ->assertStatus(422);

    expect($this->studio->fresh()->about)->not->toBe('should not stick');
});

test('the same section twice is rejected', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_order' => [StudioSection::Guides->value, StudioSection::Guides->value],
        ])
        ->assertStatus(422);
});

test('a stranger cannot rearrange the page', function () {
    $this->actingAs($this->stranger)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_order' => [StudioSection::Guides->value],
        ])
        ->assertForbidden();

    expect($this->studio->fresh()->section_order)->toBeNull();
});

test('every section carries a name', function () {
    foreach (StudioSection::cases() as $section) {
        expect($section->label())->not->toBeEmpty();
    }
});
