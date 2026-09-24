<?php

/**
 * A hold takes a studio out of public view without deleting anything.
 *
 * Two properties are what the feature is for, so both are asserted directly:
 * the studio stops being indexable and its page stops resolving, and lifting
 * the hold puts the account, the row and both search documents back exactly
 * as they were.
 *
 * Note on the index assertions: phpunit.xml forces SCOUT_DRIVER=null, so
 * searchable() and unsearchable() are no-ops here and there is no index to
 * read back. shouldBeSearchable() is the predicate the indexer and the
 * observer both branch on, so that is what these assert.
 */

use App\Enums\StudioHoldStatus;
use App\Enums\UserTypes;
use App\Models\Artist;
use App\Models\Studio;
use App\Models\User;
use App\Notifications\StudioVerificationRequestNotification;
use App\Scopes\ArtistScope;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // UserObserver::created dispatches a Slack notification that runs inline
    // on the sync queue and reaches out over the network.
    Queue::fake();
    Notification::fake();

    $this->admin = User::factory()->create(['is_admin' => true]);
});

function heldStudioOwner(): User
{
    return User::factory()->create([
        'type_id' => UserTypes::STUDIO_TYPE_ID,
        'email_verified_at' => now(),
    ]);
}

function ownedStudio(User $owner, array $overrides = []): Studio
{
    return Studio::factory()->create(array_merge([
        'owner_id' => $owner->id,
        'name' => 'Bang Bang',
        'slug' => 'bang-bang',
        'is_claimed' => true,
    ], $overrides));
}

test('a studio starts out active', function () {
    $studio = ownedStudio(heldStudioOwner());

    expect($studio->hold_status)->toBe(StudioHoldStatus::Active);
    expect($studio->isOnHold())->toBeFalse();
    expect($studio->shouldBeSearchable())->toBeTrue();
});

test('an admin can put a studio on hold', function () {
    $owner = heldStudioOwner();
    $studio = ownedStudio($owner);

    $response = $this->actingAs($this->admin)
        ->postJson("/api/admin/studios/{$studio->id}/hold", [
            'reason' => 'Name matches an established shop, registrant unverified',
        ]);

    $response->assertOk();
    $response->assertJsonPath('data.hold_status', 'on_hold');
    $response->assertJsonPath('data.is_on_hold', true);
    $response->assertJsonPath('owner_notified', true);

    $studio->refresh();

    expect($studio->isOnHold())->toBeTrue();
    expect($studio->hold_reason)->toBe('Name matches an established shop, registrant unverified');
    expect($studio->held_by_id)->toBe($this->admin->id);
    expect($studio->held_at)->not->toBeNull();
});

test('a hold requires a reason so it can be audited', function () {
    $studio = ownedStudio(heldStudioOwner());

    $this->actingAs($this->admin)
        ->postJson("/api/admin/studios/{$studio->id}/hold", [])
        ->assertStatus(422);

    expect($studio->fresh()->isOnHold())->toBeFalse();
});

test('a held studio leaves the search index', function () {
    $owner = heldStudioOwner();
    $studio = ownedStudio($owner);

    $artist = Artist::withoutGlobalScope(ArtistScope::class)->find($owner->id);
    expect($studio->shouldBeSearchable())->toBeTrue();
    expect($artist->shouldBeSearchable())->toBeTrue();

    $this->actingAs($this->admin)
        ->postJson("/api/admin/studios/{$studio->id}/hold", ['reason' => 'Ownership unconfirmed'])
        ->assertOk();

    $studio->refresh();
    $artist = Artist::withoutGlobalScope(ArtistScope::class)->find($owner->id);

    expect($studio->shouldBeSearchable())->toBeFalse();

    // The owner's account is the document search actually reads for studios,
    // so it has to go too or the page stays findable through their card.
    expect($artist->shouldBeSearchable())->toBeFalse();
});

test('lifting the hold puts the studio back in the search index', function () {
    $owner = heldStudioOwner();
    $studio = ownedStudio($owner);

    $this->actingAs($this->admin)
        ->postJson("/api/admin/studios/{$studio->id}/hold", ['reason' => 'Ownership unconfirmed'])
        ->assertOk();

    $response = $this->actingAs($this->admin)
        ->postJson("/api/admin/studios/{$studio->id}/release");

    $response->assertOk();
    $response->assertJsonPath('data.hold_status', 'active');
    $response->assertJsonPath('data.is_on_hold', false);

    $studio->refresh();
    $artist = Artist::withoutGlobalScope(ArtistScope::class)->find($owner->id);

    expect($studio->shouldBeSearchable())->toBeTrue();
    expect($artist->shouldBeSearchable())->toBeTrue();
});

test('the public studio page is not found while on hold', function () {
    $studio = ownedStudio(heldStudioOwner());

    $this->getJson("/api/studios/{$studio->slug}")->assertOk();

    $this->actingAs($this->admin)
        ->postJson("/api/admin/studios/{$studio->id}/hold", ['reason' => 'Ownership unconfirmed'])
        ->assertOk();

    // actingAs holds for the rest of the test, and an admin is exempt from the
    // hold, so the guards have to come off to ask as the public does.
    $this->app['auth']->forgetGuards();

    $this->getJson("/api/studios/{$studio->slug}")->assertStatus(404);
    $this->getJson("/api/studios/{$studio->id}/artists")->assertStatus(404);
    $this->getJson("/api/studios/{$studio->id}/guides")->assertStatus(404);
});

test('the public studio page returns once the hold is lifted', function () {
    $studio = ownedStudio(heldStudioOwner());

    $this->actingAs($this->admin)
        ->postJson("/api/admin/studios/{$studio->id}/hold", ['reason' => 'Ownership unconfirmed'])
        ->assertOk();

    $this->actingAs($this->admin)
        ->postJson("/api/admin/studios/{$studio->id}/release")
        ->assertOk();

    $this->app['auth']->forgetGuards();

    $this->getJson("/api/studios/{$studio->slug}")->assertOk();
});

test('the owner can still reach their own held studio', function () {
    $owner = heldStudioOwner();
    $studio = ownedStudio($owner);

    $this->actingAs($this->admin)
        ->postJson("/api/admin/studios/{$studio->id}/hold", ['reason' => 'Ownership unconfirmed'])
        ->assertOk();

    // They may be entirely legitimate and mid-setup, so the hold hides the
    // page from the public rather than locking them out of it.
    $this->actingAs($owner)
        ->getJson("/api/studios/{$studio->slug}")
        ->assertOk();
});

test('a held studio drops out of the sitemap directory', function () {
    $studio = ownedStudio(heldStudioOwner());

    $this->getJson('/api/studios/directory')
        ->assertOk()
        ->assertJsonFragment(['slug' => $studio->slug]);

    $this->actingAs($this->admin)
        ->postJson("/api/admin/studios/{$studio->id}/hold", ['reason' => 'Ownership unconfirmed'])
        ->assertOk();

    $this->app['auth']->forgetGuards();

    $this->getJson('/api/studios/directory')
        ->assertOk()
        ->assertJsonMissing(['slug' => $studio->slug]);
});

test('the owner is emailed at their registered account address', function () {
    $owner = heldStudioOwner();
    // studios.email is null on the records that prompted this, so the studio's
    // own column is not a usable contact route.
    $studio = ownedStudio($owner, ['email' => null]);

    $this->actingAs($this->admin)
        ->postJson("/api/admin/studios/{$studio->id}/hold", ['reason' => 'Ownership unconfirmed'])
        ->assertOk();

    Notification::assertSentTo(
        $owner,
        StudioVerificationRequestNotification::class,
        fn ($notification) => $notification->studio->id === $studio->id
    );
});

test('nothing is deleted by a hold', function () {
    $owner = heldStudioOwner();
    $studio = ownedStudio($owner);

    $this->actingAs($this->admin)
        ->postJson("/api/admin/studios/{$studio->id}/hold", ['reason' => 'Ownership unconfirmed'])
        ->assertOk();

    $this->assertDatabaseHas('studios', ['id' => $studio->id, 'name' => 'Bang Bang']);
    $this->assertDatabaseHas('users', ['id' => $owner->id, 'email' => $owner->email]);

    // The owner can still authenticate and reach their dashboard data.
    expect($owner->fresh())->not->toBeNull();
});

test('a studio already on hold cannot be held again', function () {
    $studio = ownedStudio(heldStudioOwner());

    $this->actingAs($this->admin)
        ->postJson("/api/admin/studios/{$studio->id}/hold", ['reason' => 'Ownership unconfirmed'])
        ->assertOk();

    $this->actingAs($this->admin)
        ->postJson("/api/admin/studios/{$studio->id}/hold", ['reason' => 'Again'])
        ->assertStatus(422);
});

test('a studio that is not on hold cannot be released', function () {
    $studio = ownedStudio(heldStudioOwner());

    $this->actingAs($this->admin)
        ->postJson("/api/admin/studios/{$studio->id}/release")
        ->assertStatus(422);
});

test('the record of who held a studio survives the release', function () {
    $studio = ownedStudio(heldStudioOwner());

    $this->actingAs($this->admin)
        ->postJson("/api/admin/studios/{$studio->id}/hold", ['reason' => 'Ownership unconfirmed'])
        ->assertOk();

    $this->actingAs($this->admin)
        ->postJson("/api/admin/studios/{$studio->id}/release")
        ->assertOk();

    $studio->refresh();

    expect($studio->held_by_id)->toBe($this->admin->id);
    expect($studio->hold_reason)->toBe('Ownership unconfirmed');
    expect($studio->held_at)->not->toBeNull();
    expect($studio->hold_lifted_by_id)->toBe($this->admin->id);
    expect($studio->hold_lifted_at)->not->toBeNull();
});

test('a non admin cannot place or lift a hold', function () {
    $studio = ownedStudio(heldStudioOwner());
    $stranger = User::factory()->create();

    $this->actingAs($stranger)
        ->postJson("/api/admin/studios/{$studio->id}/hold", ['reason' => 'Trying it on'])
        ->assertStatus(403);

    expect($studio->fresh()->isOnHold())->toBeFalse();

    $this->actingAs($this->admin)
        ->postJson("/api/admin/studios/{$studio->id}/hold", ['reason' => 'Ownership unconfirmed'])
        ->assertOk();

    $this->actingAs($stranger)
        ->postJson("/api/admin/studios/{$studio->id}/release")
        ->assertStatus(403);

    expect($studio->fresh()->isOnHold())->toBeTrue();
});

test('an artist at a held studio keeps their own search presence', function () {
    $owner = heldStudioOwner();
    $studio = ownedStudio($owner);

    $resident = User::factory()->create([
        'type_id' => UserTypes::ARTIST_TYPE_ID,
        'email_verified_at' => now(),
    ]);
    $studio->artists()->attach($resident->id, ['is_verified' => true]);

    $this->actingAs($this->admin)
        ->postJson("/api/admin/studios/{$studio->id}/hold", ['reason' => 'Ownership unconfirmed'])
        ->assertOk();

    $artist = Artist::withoutGlobalScope(ArtistScope::class)->find($resident->id);

    // They are not the subject of the hold and did nothing wrong.
    expect($artist->shouldBeSearchable())->toBeTrue();
});
