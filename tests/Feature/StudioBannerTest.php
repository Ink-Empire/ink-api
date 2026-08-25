<?php

use App\Enums\UserTypes;
use App\Models\Image;
use App\Models\Studio;
use App\Models\User;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create(['type_id' => UserTypes::STUDIO_TYPE_ID]);
    $this->stranger = User::factory()->create(['type_id' => UserTypes::ARTIST_TYPE_ID]);
    $this->studio = Studio::factory()->create(['owner_id' => $this->owner->id]);
    $this->image = Image::factory()->create();
});

test('the owner can set a studio banner', function () {
    $this->actingAs($this->owner)
        ->postJson("/api/studios/{$this->studio->id}/banner", ['image_id' => $this->image->id])
        ->assertOk();

    expect($this->studio->fresh()->banner_image_id)->toBe($this->image->id);
});

test('a stranger cannot set a studio banner', function () {
    $this->actingAs($this->stranger)
        ->postJson("/api/studios/{$this->studio->id}/banner", ['image_id' => $this->image->id])
        ->assertForbidden();

    expect($this->studio->fresh()->banner_image_id)->toBeNull();
});

test('setting a banner requires a real image', function () {
    $this->actingAs($this->owner)
        ->postJson("/api/studios/{$this->studio->id}/banner", ['image_id' => 99999999])
        ->assertStatus(422);
});

test('the owner can remove the banner', function () {
    $this->studio->update(['banner_image_id' => $this->image->id]);

    $this->actingAs($this->owner)
        ->deleteJson("/api/studios/{$this->studio->id}/banner")
        ->assertOk();

    expect($this->studio->fresh()->banner_image_id)->toBeNull();
});

test('a stranger cannot remove the banner', function () {
    $this->studio->update(['banner_image_id' => $this->image->id]);

    $this->actingAs($this->stranger)
        ->deleteJson("/api/studios/{$this->studio->id}/banner")
        ->assertForbidden();

    expect($this->studio->fresh()->banner_image_id)->toBe($this->image->id);
});

test('the studio resource exposes the banner', function () {
    $this->studio->update(['banner_image_id' => $this->image->id]);

    $this->getJson("/api/studios/{$this->studio->slug}")
        ->assertOk()
        ->assertJsonPath('studio.banner.id', $this->image->id);
});

test('the banner is null when none is set', function () {
    $this->getJson("/api/studios/{$this->studio->slug}")
        ->assertOk()
        ->assertJsonPath('studio.banner', null);
});
