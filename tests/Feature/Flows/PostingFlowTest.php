<?php

/**
 * Posting: creating work, the three post types, and who gets to see it.
 *
 * Approve/reject mechanics already live in TattooApprovalContractTest, so this
 * covers the ground that does not: creation through the pre-uploaded image
 * path, flash and seeking specifics, and the visibility rules that decide
 * whether a post reaches the public at all.
 */

use App\Enums\ArtistTattooApprovalStatus;
use App\Enums\PostType;
use App\Models\Image;
use App\Models\Style;
use App\Models\TattooLead;
use App\Models\Tattoo;
use App\Models\User;
use App\Notifications\ArtistTaggedNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->artist = User::factory()->asArtist()->create(['email_verified_at' => now()]);
    $this->client = User::factory()->create(['email_verified_at' => now()]);
    $this->image = Image::factory()->create();
    $this->style = Style::factory()->create();

    $this->createPost = function (array $overrides = [], ?User $as = null) {
        return $this->actingAs($as ?? $this->artist, 'sanctum')
            ->postJson('/api/tattoos/create', array_merge([
                'image_ids' => [$this->image->id],
                'title' => 'Neo-traditional dragon',
                'description' => 'Upper arm, bold colour.',
            ], $overrides));
    };
});

describe('Creating a post', function () {

    it('creates a portfolio post by default', function () {
        ($this->createPost)()->assertSuccessful();

        $tattoo = Tattoo::latest('id')->first();

        expect($tattoo)->not->toBeNull()
            ->and($tattoo->post_type)->toBe(PostType::PORTFOLIO)
            ->and($tattoo->uploaded_by_user_id)->toBe($this->artist->id);
    });

    it('creates a flash post carrying price and size', function () {
        ($this->createPost)([
            'post_type' => PostType::FLASH,
            'flash_price' => '250',
            'flash_size' => '4x6 in',
        ])->assertSuccessful();

        $tattoo = Tattoo::latest('id')->first();

        expect($tattoo->post_type)->toBe(PostType::FLASH)
            ->and($tattoo->flash_price)->not->toBeNull()
            ->and($tattoo->flash_size)->toBe('4x6 in');
    });

    it('creates a seeking post carrying timing and location', function () {
        ($this->createPost)([
            'post_type' => PostType::SEEKING,
            'timing' => 'month',
            'seeking_location' => 'Louisville, KY',
            'seeking_radius' => 50,
        ], $this->client)->assertSuccessful();

        $tattoo = Tattoo::latest('id')->first();

        expect($tattoo->post_type)->toBe(PostType::SEEKING)
            ->and($tattoo->tattoo_lead_id)->not->toBeNull();

        // The seeking details are carried by the linked beacon, not the post
        $lead = TattooLead::find($tattoo->tattoo_lead_id);

        expect($lead->timing)->toBe('month')
            ->and((bool) $lead->is_active)->toBeTrue();
    });

    it('defaults a seeking post to allowing artist contact', function () {
        ($this->createPost)([
            'post_type' => PostType::SEEKING,
            'timing' => 'week',
        ], $this->client)->assertSuccessful();

        $lead = TattooLead::find(Tattoo::latest('id')->first()->tattoo_lead_id);

        expect((bool) $lead->allow_artist_contact)->toBeTrue();
    });

    it('refuses to create a post with no images', function () {
        $this->actingAs($this->artist, 'sanctum')
            ->postJson('/api/tattoos/create', ['title' => 'No pictures'])
            ->assertStatus(400);
    });

    it('requires authentication', function () {
        $this->postJson('/api/tattoos/create', [
            'image_ids' => [$this->image->id],
            'title' => 'Anonymous upload',
        ])->assertStatus(401);
    });
});

describe('Tagging an artist in someone else’s post', function () {

    it('holds the post for approval and tells the artist', function () {
        ($this->createPost)([
            'tagged_artist_id' => $this->artist->id,
        ], $this->client)->assertSuccessful();

        $tattoo = Tattoo::latest('id')->first();

        expect($tattoo->artist_id)->toBe($this->artist->id)
            ->and($tattoo->approval_status)->toBe(ArtistTattooApprovalStatus::PENDING);

        Notification::assertSentTo($this->artist, ArtistTaggedNotification::class);
    });

    it('leaves an untagged post owned by the uploader alone', function () {
        ($this->createPost)([], $this->client)->assertSuccessful();

        $tattoo = Tattoo::latest('id')->first();

        expect($tattoo->approval_status)->toBe(ArtistTattooApprovalStatus::USER_ONLY);
        Notification::assertNothingSent();
    });
});

describe('Visibility', function () {

    it('publishes an artist’s own post immediately', function () {
        ($this->createPost)()->assertSuccessful();

        expect((bool) Tattoo::latest('id')->first()->is_visible)->toBeTrue();
    });

    it('makes a tagged post visible once the artist approves it', function () {
        $tattoo = Tattoo::factory()->create([
            'artist_id' => $this->artist->id,
            'uploaded_by_user_id' => $this->client->id,
            'approval_status' => ArtistTattooApprovalStatus::PENDING,
            'is_visible' => false,
            'primary_image_id' => $this->image->id,
        ]);

        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/tattoos/{$tattoo->id}/approve", ['action' => 'approve'])
            ->assertSuccessful();

        $tattoo->refresh();

        expect($tattoo->approval_status)->toBe(ArtistTattooApprovalStatus::APPROVED)
            ->and((bool) $tattoo->is_visible)->toBeTrue();
    });

    it('keeps a rejected post off the artist’s profile', function () {
        $tattoo = Tattoo::factory()->create([
            'artist_id' => $this->artist->id,
            'uploaded_by_user_id' => $this->client->id,
            'approval_status' => ArtistTattooApprovalStatus::PENDING,
            'is_visible' => false,
            'primary_image_id' => $this->image->id,
        ]);

        $this->actingAs($this->artist, 'sanctum')
            ->postJson("/api/tattoos/{$tattoo->id}/approve", ['action' => 'reject'])
            ->assertSuccessful();

        expect($tattoo->fresh()->artist_id)->not->toBe($this->artist->id);
    });
});
