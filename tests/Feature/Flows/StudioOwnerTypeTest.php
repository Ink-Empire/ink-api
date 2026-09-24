<?php

/**
 * A studio account represents the business, so the owner's own user row
 * carries the studio type.
 *
 * Only the register path set it. Studios made by an already-authenticated user
 * and studios claimed off a Google Places listing left the owner as a client,
 * which kept them out of the artists index entirely - that index holds studio
 * accounts as well as artists - and sent them down the wrong branch in
 * VerifyEmailController, UserController and ImageController.
 *
 * An artist who owns a studio deliberately keeps type 2: relabelling them would
 * pull their portfolio out of the artist side of search.
 */

use App\Enums\UserTypes;
use App\Models\Studio;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // The user observer notifies Slack on create, which runs inline on the
    // sync queue and reaches out over the network.
    Queue::fake();
    Notification::fake();
});

function studioPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Bang Bang',
        'slug' => 'bang-bang',
        'about' => 'Walk-ins welcome',
        'location' => 'Brooklyn, NY',
        'email' => 'hello@bangbang.test',
        'phone' => '555-0100',
    ], $overrides);
}

test('register with type studio still sets the studio type', function () {
    $response = $this->postJson('/api/register', [
        'name' => 'Bang Bang',
        'email' => 'owner@bangbang.test',
        'email_confirmation' => 'owner@bangbang.test',
        'password' => 'Correct-horse-9',
        'password_confirmation' => 'Correct-horse-9',
        'username' => 'bangbang',
        'slug' => 'bang-bang',
        'type' => UserTypes::STUDIO,
        'studio_email' => 'hello@bangbang.test',
        'has_accepted_toc' => true,
        'has_accepted_privacy_policy' => true,
    ]);

    $response->assertCreated();

    $owner = User::where('email', 'owner@bangbang.test')->firstOrFail();

    expect($owner->type_id)->toBe(UserTypes::STUDIO_TYPE_ID)
        ->and(Studio::where('owner_id', $owner->id)->exists())->toBeTrue();
});

test('creating a studio gives a client owner the studio type', function () {
    $owner = User::factory()->create(['type_id' => UserTypes::CLIENT_TYPE_ID]);

    $response = $this->actingAs($owner)->postJson('/api/studios', studioPayload());

    $response->assertOk();

    expect($owner->fresh()->type_id)->toBe(UserTypes::STUDIO_TYPE_ID)
        ->and(Studio::where('owner_id', $owner->id)->exists())->toBeTrue();
});

test('claiming a studio gives a client owner the studio type', function () {
    $owner = User::factory()->create(['type_id' => UserTypes::CLIENT_TYPE_ID]);
    $studio = Studio::factory()->create(['owner_id' => null, 'is_claimed' => false]);

    $response = $this->actingAs($owner)->postJson("/api/studios/{$studio->id}/claim", [
        'about' => 'Walk-ins welcome',
        'phone' => '555-0100',
    ]);

    $response->assertOk();

    expect($owner->fresh()->type_id)->toBe(UserTypes::STUDIO_TYPE_ID)
        ->and($studio->fresh()->owner_id)->toBe($owner->id)
        ->and($studio->fresh()->is_claimed)->toBeTrue();
});

test('an artist who creates a studio keeps the artist type', function () {
    $owner = User::factory()->asArtist()->create();

    $this->actingAs($owner)->postJson('/api/studios', studioPayload())->assertOk();

    expect($owner->fresh()->type_id)->toBe(UserTypes::ARTIST_TYPE_ID)
        ->and(Studio::where('owner_id', $owner->id)->exists())->toBeTrue();
});

test('an artist who claims a studio keeps the artist type', function () {
    $owner = User::factory()->asArtist()->create();
    $studio = Studio::factory()->create(['owner_id' => null, 'is_claimed' => false]);

    $this->actingAs($owner)
        ->postJson("/api/studios/{$studio->id}/claim", ['phone' => '555-0100'])
        ->assertOk();

    expect($owner->fresh()->type_id)->toBe(UserTypes::ARTIST_TYPE_ID)
        ->and($studio->fresh()->owner_id)->toBe($owner->id);
});

test('a studio account that creates a studio keeps the studio type', function () {
    $owner = User::factory()->create(['type_id' => UserTypes::STUDIO_TYPE_ID]);

    $this->actingAs($owner)->postJson('/api/studios', studioPayload())->assertOk();

    expect($owner->fresh()->type_id)->toBe(UserTypes::STUDIO_TYPE_ID);
});

test('the owner is the caller, not an owner_id in the payload', function () {
    $caller = User::factory()->create(['type_id' => UserTypes::CLIENT_TYPE_ID]);
    $stranger = User::factory()->create(['type_id' => UserTypes::CLIENT_TYPE_ID]);

    $this->actingAs($caller)
        ->postJson('/api/studios', studioPayload(['owner_id' => $stranger->id]))
        ->assertOk();

    expect(Studio::where('owner_id', $caller->id)->exists())->toBeTrue()
        ->and(Studio::where('owner_id', $stranger->id)->exists())->toBeFalse()
        ->and($stranger->fresh()->type_id)->toBe(UserTypes::CLIENT_TYPE_ID);
});

test('an owner cannot create a second studio', function () {
    $owner = User::factory()->create(['type_id' => UserTypes::CLIENT_TYPE_ID]);
    Studio::factory()->create(['owner_id' => $owner->id]);

    $response = $this->actingAs($owner)->postJson('/api/studios', studioPayload());

    $response->assertStatus(422);

    expect(Studio::where('owner_id', $owner->id)->count())->toBe(1);
});

test('an owner cannot claim a studio on top of the one they have', function () {
    $owner = User::factory()->create(['type_id' => UserTypes::CLIENT_TYPE_ID]);
    Studio::factory()->create(['owner_id' => $owner->id]);
    $other = Studio::factory()->create(['owner_id' => null, 'is_claimed' => false]);

    $response = $this->actingAs($owner)->postJson("/api/studios/{$other->id}/claim", []);

    $response->assertStatus(422);

    expect($other->fresh()->owner_id)->toBeNull()
        ->and($other->fresh()->is_claimed)->toBeFalse();
});

test('an already claimed studio cannot be claimed again', function () {
    $owner = User::factory()->create(['type_id' => UserTypes::CLIENT_TYPE_ID]);
    $studio = Studio::factory()->create(['owner_id' => null, 'is_claimed' => true]);

    $this->actingAs($owner)
        ->postJson("/api/studios/{$studio->id}/claim", [])
        ->assertStatus(422);

    expect($studio->fresh()->owner_id)->toBeNull();
});
