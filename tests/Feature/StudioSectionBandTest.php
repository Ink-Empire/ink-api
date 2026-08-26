<?php

use App\Enums\StudioSection;
use App\Enums\StudioSectionBand;
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

test('a studio that has never moved a section between bands stores none', function () {
    $this->getJson("/api/studios/{$this->studio->slug}")
        ->assertOk()
        ->assertJsonPath('studio.section_bands', []);
});

test('the owner can lift a section out of the info tab', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_bands' => [StudioSection::Guides->value => StudioSectionBand::Feature->value],
        ])
        ->assertOk();

    $this->getJson("/api/studios/{$this->studio->slug}")
        ->assertOk()
        ->assertJsonPath('studio.section_bands.guides', StudioSectionBand::Feature->value);
});

test('band moves stay sparse rather than being filled in', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_bands' => [StudioSection::Guides->value => StudioSectionBand::Feature->value],
        ])
        ->assertOk();

    // The layout still decides where an unmoved section starts, so the client
    // needs to know which of these were the studio's own choice.
    expect($this->studio->fresh()->sectionBands())
        ->toBe([StudioSection::Guides->value => StudioSectionBand::Feature->value]);
});

test('an unknown section is rejected and nothing else applies', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'about' => 'should not stick',
            'section_bands' => ['newsletter' => StudioSectionBand::Feature->value],
        ])
        ->assertStatus(422);

    expect($this->studio->fresh()->about)->not->toBe('should not stick');
});

test('an unknown band is rejected', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_bands' => [StudioSection::Guides->value => 'sidebar'],
        ])
        ->assertStatus(422);
});

test('a stranger cannot move a section between bands', function () {
    $this->actingAs($this->stranger)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_bands' => [StudioSection::Guides->value => StudioSectionBand::Feature->value],
        ])
        ->assertForbidden();

    expect($this->studio->fresh()->section_bands)->toBeNull();
});

test('the whole arrangement publishes in one call', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_order' => [
                StudioSection::Spotlight->value,
                StudioSection::Guides->value,
                StudioSection::Location->value,
                StudioSection::Hours->value,
                StudioSection::Contact->value,
                StudioSection::Artists->value,
            ],
            'section_widths' => [StudioSection::Spotlight->value => 'half'],
            'section_columns' => [StudioSection::Guides->value => 'right'],
            'section_bands' => [StudioSection::Guides->value => StudioSectionBand::Feature->value],
        ])
        ->assertOk();

    $studio = $this->studio->fresh();

    expect($studio->sectionOrder()[0])->toBe(StudioSection::Spotlight->value)
        ->and($studio->sectionWidths()['spotlight'])->toBe('half')
        ->and($studio->sectionColumns()['guides'])->toBe('right')
        ->and($studio->sectionBands()['guides'])->toBe(StudioSectionBand::Feature->value);
});

test('every band carries a name', function () {
    foreach (StudioSectionBand::cases() as $band) {
        expect($band->label())->not->toBeEmpty();
    }
});
