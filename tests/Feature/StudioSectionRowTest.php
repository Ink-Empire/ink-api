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

test('a studio that has never placed a section stores no rows', function () {
    $this->getJson("/api/studios/{$this->studio->slug}")
        ->assertOk()
        ->assertJsonPath('studio.section_rows', []);
});

test('a row and a column together place a section in a cell', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_rows' => [StudioSection::Contact->value => 1],
            'section_columns' => [StudioSection::Contact->value => StudioSectionColumn::Right->value],
        ])
        ->assertOk();

    $studio = $this->studio->fresh();

    expect($studio->sectionRows()['contact'])->toBe(1)
        ->and($studio->sectionColumns()['contact'])->toBe(StudioSectionColumn::Right->value);
});

test('a gap is a real position: row one can be filled while row zero stays empty', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_rows' => [
                StudioSection::Hours->value => 0,
                StudioSection::Spotlight->value => 1,
                StudioSection::Contact->value => 1,
            ],
            'section_columns' => [
                StudioSection::Hours->value => StudioSectionColumn::Left->value,
                StudioSection::Spotlight->value => StudioSectionColumn::Left->value,
                StudioSection::Contact->value => StudioSectionColumn::Right->value,
            ],
        ])
        ->assertOk();

    $rows = $this->studio->fresh()->sectionRows();

    // Nothing occupies row zero of the right column, and that is the point.
    expect($rows['hours'])->toBe(0)
        ->and($rows['spotlight'])->toBe(1)
        ->and($rows['contact'])->toBe(1);
});

test('an unknown section is rejected and nothing else applies', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'about' => 'should not stick',
            'section_rows' => ['newsletter' => 0],
        ])
        ->assertStatus(422);

    expect($this->studio->fresh()->about)->not->toBe('should not stick');
});

test('a negative row is rejected', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_rows' => [StudioSection::Contact->value => -1],
        ])
        ->assertStatus(422);
});

test('a row beyond the page is rejected', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_rows' => [StudioSection::Contact->value => 999],
        ])
        ->assertStatus(422);
});

test('a stranger cannot place a section in a row', function () {
    $this->actingAs($this->stranger)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'section_rows' => [StudioSection::Contact->value => 2],
        ])
        ->assertForbidden();

    expect($this->studio->fresh()->section_rows)->toBeNull();
});
