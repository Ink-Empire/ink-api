<?php

use App\Enums\StudioTemplate;
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

test('a studio starts on the portfolio layout', function () {
    $this->getJson("/api/studios/{$this->studio->slug}")
        ->assertOk()
        ->assertJsonPath('studio.template', StudioTemplate::Portfolio->value);
});

test('the owner can publish a different layout', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", ['template' => StudioTemplate::Team->value])
        ->assertOk();

    expect($this->studio->fresh()->template)->toBe(StudioTemplate::Team);
});

test('an unknown layout is rejected and nothing else applies', function () {
    $this->actingAs($this->owner)
        ->putJson("/api/studios/{$this->studio->id}/page", [
            'about' => 'should not stick',
            'template' => 'magazine',
        ])
        ->assertStatus(422);

    expect($this->studio->fresh()->about)->not->toBe('should not stick');
});

test('a stranger cannot change the layout', function () {
    $this->actingAs($this->stranger)
        ->putJson("/api/studios/{$this->studio->id}/page", ['template' => StudioTemplate::Storefront->value])
        ->assertForbidden();

    expect($this->studio->fresh()->template)->toBe(StudioTemplate::Portfolio);
});

test('every layout carries a name and an explanation', function () {
    foreach (StudioTemplate::cases() as $template) {
        expect($template->label())->not->toBeEmpty()
            ->and($template->description())->not->toBeEmpty();
    }
});
