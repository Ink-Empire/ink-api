<?php

use App\Enums\SpotlightType;
use App\Enums\UserTypes;
use App\Models\Image;
use App\Models\Studio;
use App\Models\StudioSpotlight;
use App\Models\Tattoo;
use App\Models\User;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create(['type_id' => UserTypes::STUDIO_TYPE_ID]);
    $this->studio = Studio::factory()->create(['owner_id' => $this->owner->id]);
});

test('a spotlighted artist comes back with their image', function () {
    $image = Image::factory()->create();
    $artist = User::factory()->create([
        'type_id' => UserTypes::ARTIST_TYPE_ID,
        'image_id' => $image->id,
    ]);

    StudioSpotlight::create([
        'studio_id' => $this->studio->id,
        'spotlightable_type' => SpotlightType::Artist->value,
        'spotlightable_id' => $artist->id,
        'display_order' => 0,
    ]);

    $this->getJson("/api/studios/{$this->studio->id}/spotlights")
        ->assertOk()
        ->assertJsonPath('spotlights.0.type', SpotlightType::Artist->value)
        ->assertJsonPath('spotlights.0.item.id', $artist->id)
        ->assertJsonPath('spotlights.0.item.image.uri', $image->uri);
});

test('a spotlighted tattoo comes back with the fields the studio page renders', function () {
    $image = Image::factory()->create();
    $artist = User::factory()->create(['type_id' => UserTypes::ARTIST_TYPE_ID]);
    $tattoo = Tattoo::factory()->create([
        'artist_id' => $artist->id,
        'primary_image_id' => $image->id,
    ]);

    StudioSpotlight::create([
        'studio_id' => $this->studio->id,
        'spotlightable_type' => SpotlightType::Tattoo->value,
        'spotlightable_id' => $tattoo->id,
        'display_order' => 0,
    ]);

    $this->getJson("/api/studios/{$this->studio->id}/spotlights")
        ->assertOk()
        ->assertJsonPath('spotlights.0.type', SpotlightType::Tattoo->value)
        ->assertJsonPath('spotlights.0.item.id', $tattoo->id)
        ->assertJsonPath('spotlights.0.item.primary_image.uri', $image->uri);
});

test('a spotlight whose target was deleted is dropped rather than rendered empty', function () {
    $artist = User::factory()->create(['type_id' => UserTypes::ARTIST_TYPE_ID]);

    StudioSpotlight::create([
        'studio_id' => $this->studio->id,
        'spotlightable_type' => SpotlightType::Artist->value,
        'spotlightable_id' => $artist->id,
        'display_order' => 0,
    ]);

    $artist->delete();

    $this->getJson("/api/studios/{$this->studio->id}/spotlights")
        ->assertOk()
        ->assertJsonCount(0, 'spotlights');
});

test('a studio with no spotlights returns an empty list', function () {
    $this->getJson("/api/studios/{$this->studio->id}/spotlights")
        ->assertOk()
        ->assertJsonCount(0, 'spotlights');
});

test('spotlights come back in display order', function () {
    $first = User::factory()->create(['type_id' => UserTypes::ARTIST_TYPE_ID]);
    $second = User::factory()->create(['type_id' => UserTypes::ARTIST_TYPE_ID]);

    StudioSpotlight::create([
        'studio_id' => $this->studio->id,
        'spotlightable_type' => SpotlightType::Artist->value,
        'spotlightable_id' => $second->id,
        'display_order' => 2,
    ]);
    StudioSpotlight::create([
        'studio_id' => $this->studio->id,
        'spotlightable_type' => SpotlightType::Artist->value,
        'spotlightable_id' => $first->id,
        'display_order' => 1,
    ]);

    $this->getJson("/api/studios/{$this->studio->id}/spotlights")
        ->assertOk()
        ->assertJsonPath('spotlights.0.item.id', $first->id)
        ->assertJsonPath('spotlights.1.item.id', $second->id);
});

test('an unknown spotlight type is rejected', function () {
    $this->actingAs($this->owner)
        ->postJson("/api/studios/{$this->studio->id}/spotlights", [
            'type' => 'billboard',
            'item_id' => 1,
        ])
        ->assertStatus(422);
});

test('the owner can pin and then unpin an artist', function () {
    $artist = User::factory()->create(['type_id' => UserTypes::ARTIST_TYPE_ID]);

    $this->actingAs($this->owner)
        ->postJson("/api/studios/{$this->studio->id}/spotlights", [
            'type' => SpotlightType::Artist->value,
            'item_id' => $artist->id,
        ])
        ->assertOk();

    $spotlight = $this->studio->spotlights()->first();
    expect($spotlight)->not->toBeNull();

    $this->actingAs($this->owner)
        ->deleteJson("/api/studios/{$this->studio->id}/spotlights/{$spotlight->id}")
        ->assertOk();

    expect($this->studio->spotlights()->count())->toBe(0);
});

test('pinning the same item twice does not duplicate it', function () {
    $artist = User::factory()->create(['type_id' => UserTypes::ARTIST_TYPE_ID]);

    foreach ([0, 1] as $order) {
        $this->actingAs($this->owner)
            ->postJson("/api/studios/{$this->studio->id}/spotlights", [
                'type' => SpotlightType::Artist->value,
                'item_id' => $artist->id,
                'display_order' => $order,
            ])
            ->assertOk();
    }

    expect($this->studio->spotlights()->count())->toBe(1);
});
