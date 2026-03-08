<?php

use App\Enums\ArtistTattooApprovalStatus;
use App\Enums\UserTypes;
use App\Models\Image;
use App\Models\Style;
use App\Models\Tattoo;
use App\Models\User;
use App\Notifications\TattooApprovedNotification;
use App\Notifications\TattooRejectedNotification;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();

    $this->artist = User::factory()->asArtist()->create([
        'email_verified_at' => now(),
    ]);

    $this->client = User::factory()->create([
        'email_verified_at' => now(),
    ]);

    $this->image = Image::factory()->create();
    $this->style = Style::factory()->create();

    $this->pendingTattoo = Tattoo::factory()->create([
        'artist_id' => $this->artist->id,
        'uploaded_by_user_id' => $this->client->id,
        'approval_status' => ArtistTattooApprovalStatus::PENDING,
        'is_visible' => false,
        'primary_image_id' => $this->image->id,
    ]);

    $this->pendingTattoo->styles()->attach($this->style->id);
    $this->pendingTattoo->images()->attach($this->image->id);
});

describe('Tattoo Pending Approvals (Story 3.1)', function () {

    it('GET /api/tattoos/pending-approvals returns pending tattoos for artist', function () {
        $this->actingAs($this->artist, 'sanctum');

        $response = $this->getJson('/api/tattoos/pending-approvals');

        $response->assertOk()
            ->assertJsonStructure([
                'tattoos' => [
                    '*' => [
                        'id',
                        'title',
                        'description',
                        'primary_image',
                        'images',
                        'styles',
                        'approval_status',
                        'uploader',
                        'created_at',
                    ],
                ],
            ]);

        $tattoos = $response->json('tattoos');
        expect($tattoos)->toHaveCount(1);
        expect($tattoos[0]['id'])->toBe($this->pendingTattoo->id);
        expect($tattoos[0]['approval_status'])->toBe('pending');

        exportFixture('tattoo-approval/pending-approvals.json', $response->json());
    });

    it('GET /api/tattoos/pending-approvals returns empty when no pending tattoos', function () {
        $otherArtist = User::factory()->asArtist()->create();

        $this->actingAs($otherArtist, 'sanctum');

        $response = $this->getJson('/api/tattoos/pending-approvals');

        $response->assertOk();
        expect($response->json('tattoos'))->toHaveCount(0);

        exportFixture('tattoo-approval/pending-approvals-empty.json', $response->json());
    });

    it('GET /api/tattoos/pending-approvals returns 403 for client user', function () {
        $this->actingAs($this->client, 'sanctum');

        $response = $this->getJson('/api/tattoos/pending-approvals');

        $response->assertStatus(403);

        exportFixture('tattoo-approval/pending-approvals-forbidden.json', $response->json());
    });

});

describe('Tattoo Approve/Reject (Stories 3.2, 3.3)', function () {

    it('POST /api/tattoos/{id}/approve approves a pending tattoo', function () {
        $this->actingAs($this->artist, 'sanctum');

        $response = $this->postJson("/api/tattoos/{$this->pendingTattoo->id}/approve", [
            'action' => 'approve',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Tattoo approved',
            ]);

        $this->pendingTattoo->refresh();
        expect($this->pendingTattoo->approval_status)->toBe(ArtistTattooApprovalStatus::APPROVED);
        expect($this->pendingTattoo->is_visible)->toBeTrue();

        Notification::assertSentTo($this->client, TattooApprovedNotification::class);

        exportFixture('tattoo-approval/approve-success.json', $response->json());
    });

    it('POST /api/tattoos/{id}/approve rejects a pending tattoo', function () {
        $this->actingAs($this->artist, 'sanctum');

        $response = $this->postJson("/api/tattoos/{$this->pendingTattoo->id}/approve", [
            'action' => 'reject',
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'Tag rejected',
            ]);

        $this->pendingTattoo->refresh();
        expect($this->pendingTattoo->artist_id)->toBeNull();
        expect($this->pendingTattoo->approval_status)->toBe(ArtistTattooApprovalStatus::USER_ONLY);
        expect($this->pendingTattoo->is_visible)->toBeFalse();

        Notification::assertSentTo($this->client, TattooRejectedNotification::class);

        exportFixture('tattoo-approval/reject-success.json', $response->json());
    });

    it('POST /api/tattoos/{id}/approve returns 403 when wrong artist approves', function () {
        $otherArtist = User::factory()->asArtist()->create();

        $this->actingAs($otherArtist, 'sanctum');

        $response = $this->postJson("/api/tattoos/{$this->pendingTattoo->id}/approve", [
            'action' => 'approve',
        ]);

        $response->assertStatus(403);

        exportFixture('tattoo-approval/approve-wrong-artist.json', $response->json());
    });

    it('POST /api/tattoos/{id}/approve returns 403 for client user', function () {
        $this->actingAs($this->client, 'sanctum');

        $response = $this->postJson("/api/tattoos/{$this->pendingTattoo->id}/approve", [
            'action' => 'approve',
        ]);

        $response->assertStatus(403);

        $this->pendingTattoo->refresh();
        expect($this->pendingTattoo->approval_status)->toBe(ArtistTattooApprovalStatus::PENDING);

        exportFixture('tattoo-approval/approve-client-forbidden.json', $response->json());
    });

    it('POST /api/tattoos/{id}/approve returns 401 for unauthenticated user', function () {
        $response = $this->postJson("/api/tattoos/{$this->pendingTattoo->id}/approve", [
            'action' => 'approve',
        ]);

        $response->assertStatus(401);
    });

    it('GET /api/tattoos/pending-approvals returns 401 for unauthenticated user', function () {
        $response = $this->getJson('/api/tattoos/pending-approvals');

        $response->assertStatus(401);
    });

    it('POST /api/tattoos/{id}/approve returns error for already approved tattoo', function () {
        $this->pendingTattoo->update([
            'approval_status' => ArtistTattooApprovalStatus::APPROVED,
        ]);

        $this->actingAs($this->artist, 'sanctum');

        $response = $this->postJson("/api/tattoos/{$this->pendingTattoo->id}/approve", [
            'action' => 'approve',
        ]);

        $response->assertStatus(400);

        exportFixture('tattoo-approval/approve-not-pending.json', $response->json());
    });

});
