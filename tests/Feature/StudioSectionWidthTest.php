<?php

use App\Enums\StudioSection;
use App\Enums\StudioSectionWidth;
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

test('a studio that has never resized anything gets the shipped widths', function () {
    $this->getJson("/api/studios/{$this->studio->slug}")
        ->assertOk()
        ->assertJsonPath('studio.section_widths.spotlight', StudioSectionWidth::Full->value)
        ->assertJsonPath('studio.section_widths.artists', StudioSectionWidth::Full->value)
        ->assertJsonPath('studio.section_widths.guides', StudioSectionWidth::Half->value)
        ->assertJsonPath('studio.section_widths.hours', StudioSectionWidth::Half->value)
        ->assertJsonPath('studio.section_widths.location', StudioSectionWidth::Half->value)
        ->assertJsonPath('studio.section_widths.contact', StudioSectionWidth::Half->value);
});

test('the owner can widen a section', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_widths' => [StudioSection::Guides->value => StudioSectionWidth::Full->value],
        ])
        ->assertOk();

    $this->getJson("/api/studios/{$this->studio->slug}")
        ->assertOk()
        ->assertJsonPath('studio.section_widths.guides', StudioSectionWidth::Full->value);
});

test('a section left out of the payload keeps the width it shipped with', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_widths' => [StudioSection::Spotlight->value => StudioSectionWidth::Half->value],
        ])
        ->assertOk();

    $widths = $this->studio->fresh()->sectionWidths();

    expect($widths['spotlight'])->toBe(StudioSectionWidth::Half->value)
        ->and($widths['artists'])->toBe(StudioSectionWidth::Full->value)
        ->and($widths['guides'])->toBe(StudioSectionWidth::Half->value)
        ->and($widths)->toHaveCount(count(StudioSection::values()));
});

test('an unknown section is rejected and nothing else applies', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'about' => 'should not stick',
            'section_widths' => ['newsletter' => StudioSectionWidth::Full->value],
        ])
        ->assertStatus(422);

    expect($this->studio->fresh()->about)->not->toBe('should not stick');
});

test('an unknown width is rejected', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_widths' => [StudioSection::Guides->value => 'third'],
        ])
        ->assertStatus(422);
});

test('a stranger cannot resize a section', function () {
    $this->actingAs($this->stranger)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_widths' => [StudioSection::Guides->value => StudioSectionWidth::Full->value],
        ])
        ->assertForbidden();

    expect($this->studio->fresh()->section_widths)->toBeNull();
});

test('every width carries a column count and a name', function () {
    foreach (StudioSectionWidth::cases() as $width) {
        expect($width->columns())->toBeGreaterThan(0)
            ->and($width->label())->not->toBeEmpty();
    }
});
