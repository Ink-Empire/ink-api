<?php

use App\Enums\StudioSection;
use App\Enums\StudioSectionColumn;
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

test('a studio that has never placed a section stores no columns', function () {
    $this->getJson("/api/studios/{$this->studio->slug}")
        ->assertOk()
        ->assertJsonPath('studio.section_columns', []);
});

test('the owner can place a section in a column', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_columns' => [StudioSection::Contact->value => StudioSectionColumn::Right->value],
        ])
        ->assertOk();

    $this->getJson("/api/studios/{$this->studio->slug}")
        ->assertOk()
        ->assertJsonPath('studio.section_columns.contact', StudioSectionColumn::Right->value);
});

test('placements stay sparse rather than being filled in', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_columns' => [StudioSection::Contact->value => StudioSectionColumn::Left->value],
        ])
        ->assertOk();

    // The fallback is positional, so the client needs to know which sections
    // were actually placed. Filling this map would erase that.
    expect($this->studio->fresh()->sectionColumns())
        ->toBe([StudioSection::Contact->value => StudioSectionColumn::Left->value]);
});

test('an unknown section is rejected and nothing else applies', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'about' => 'should not stick',
            'section_columns' => ['newsletter' => StudioSectionColumn::Left->value],
        ])
        ->assertStatus(422);

    expect($this->studio->fresh()->about)->not->toBe('should not stick');
});

test('an unknown column is rejected', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_columns' => [StudioSection::Contact->value => 'middle'],
        ])
        ->assertStatus(422);
});

test('a stranger cannot place a section', function () {
    $this->actingAs($this->stranger)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_columns' => [StudioSection::Contact->value => StudioSectionColumn::Right->value],
        ])
        ->assertForbidden();

    expect($this->studio->fresh()->section_columns)->toBeNull();
});

test('order, width and column publish together', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_order' => [
                StudioSection::Guides->value,
                StudioSection::Contact->value,
                StudioSection::Spotlight->value,
                StudioSection::Artists->value,
                StudioSection::Location->value,
                StudioSection::Hours->value,
            ],
            'section_widths' => [StudioSection::Spotlight->value => 'half'],
            'section_columns' => [StudioSection::Contact->value => StudioSectionColumn::Right->value],
        ])
        ->assertOk();

    $studio = $this->studio->fresh();

    expect($studio->sectionOrder()[0])->toBe(StudioSection::Guides->value)
        ->and($studio->sectionWidths()['spotlight'])->toBe('half')
        ->and($studio->sectionColumns()['contact'])->toBe(StudioSectionColumn::Right->value);
});

test('every column carries a name', function () {
    foreach (StudioSectionColumn::cases() as $column) {
        expect($column->label())->not->toBeEmpty();
    }
});
