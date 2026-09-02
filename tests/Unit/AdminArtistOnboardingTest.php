<?php

namespace Tests\Unit;

use App\Models\BulkUpload;
use App\Models\Studio;
use App\Models\User;
use App\Notifications\InboundEmailReceiptNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\RefreshTestDatabase;

class AdminArtistOnboardingTest extends TestCase
{
    use RefreshTestDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();

        $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
    }

    public function test_it_creates_a_claimable_artist_from_submitted_images(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/artists/onboard', $this->payload())
            ->assertCreated()
            ->assertJsonPath('is_new_account', true);

        $artist = User::where('email', 'newartist@example.com')->first();

        $this->assertNotNull($artist);
        $this->assertTrue((bool) $artist->force_password_reset);
        $this->assertNotNull($artist->email_verified_at);
    }

    /**
     * The receipt carries the temp password. Without it the artist has a page
     * they cannot get into, which defeats the point of making it claimable.
     */
    public function test_the_artist_is_sent_their_credentials(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/artists/onboard', $this->payload())
            ->assertCreated();

        $artist = User::where('email', 'newartist@example.com')->first();

        Notification::assertSentTo($artist, InboundEmailReceiptNotification::class);
    }

    /**
     * Recorded so the review queue can tell an admin built page apart from one
     * that arrived through the setup mailbox or a bulk upload.
     */
    public function test_the_batch_is_recorded_as_coming_from_admin(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/artists/onboard', $this->payload())
            ->assertCreated();

        $this->assertSame('admin', BulkUpload::latest('id')->first()->source);
    }

    public function test_an_existing_artist_keeps_their_account(): void
    {
        $existing = User::factory()->asArtist()->create(['email' => 'known@example.com']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/artists/onboard', $this->payload(['email' => 'known@example.com']))
            ->assertCreated()
            ->assertJsonPath('is_new_account', false);

        $this->assertSame(1, User::where('email', 'known@example.com')->count());
        $this->assertSame($existing->id, BulkUpload::latest('id')->first()->artist_id);
    }

    /**
     * Recorded as initiated by the artist so it lands in the studio owner's
     * existing join requests, and left unverified so the studio still confirms
     * rather than an admin deciding for them.
     */
    public function test_it_attaches_the_artist_to_a_studio_when_one_is_given(): void
    {
        $studio = Studio::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/artists/onboard', $this->payload(['studio_id' => $studio->id]))
            ->assertCreated()
            ->assertJsonPath('studio.id', $studio->id);

        $artist = User::where('email', 'newartist@example.com')->first();
        $pivot = $studio->artists()->where('users.id', $artist->id)->first()->pivot;

        $this->assertFalse((bool) $pivot->is_verified);
        $this->assertSame('artist', $pivot->initiated_by);
    }

    public function test_the_studio_is_optional(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/artists/onboard', $this->payload())
            ->assertCreated()
            ->assertJsonPath('studio', null);
    }

    public function test_it_refuses_a_studio_that_does_not_exist(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/artists/onboard', $this->payload(['studio_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('studio_id');
    }

    /**
     * The coordinates are the point. Proximity search reads them, and
     * users:backfill-timezones derives the timezone from them, without which
     * the artist's bookings sync to Google in UTC.
     */
    public function test_it_stores_the_location_with_its_coordinates(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/artists/onboard', $this->payload([
                'location' => 'Brooklyn, NY, USA',
                'location_lat_long' => '40.6782,-73.9442',
            ]))
            ->assertCreated();

        $artist = User::where('email', 'newartist@example.com')->first();

        $this->assertSame('Brooklyn, NY, USA', $artist->location);
        $this->assertSame('40.6782,-73.9442', $artist->location_lat_long);
    }

    /**
     * A location with no coordinates is worse than none, because it looks set
     * while leaving the artist unsearchable and without a timezone.
     */
    public function test_it_ignores_a_location_that_has_no_coordinates(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/artists/onboard', $this->payload(['location' => 'Somewhere']))
            ->assertCreated();

        $artist = User::where('email', 'newartist@example.com')->first();

        $this->assertNull($artist->location_lat_long);
        $this->assertNotSame('Somewhere', $artist->location);
    }

    public function test_it_refuses_coordinates_that_are_not_a_lat_long_pair(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/artists/onboard', $this->payload([
                'location' => 'Brooklyn',
                'location_lat_long' => 'not coordinates',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('location_lat_long');
    }

    public function test_it_does_not_overwrite_a_location_the_artist_already_set(): void
    {
        User::factory()->asArtist()->create([
            'email' => 'known@example.com',
            'location' => 'Their own city',
            'location_lat_long' => '1.0,2.0',
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/artists/onboard', $this->payload([
                'email' => 'known@example.com',
                'location' => 'Brooklyn, NY, USA',
                'location_lat_long' => '40.6782,-73.9442',
            ]))
            ->assertCreated();

        $artist = User::where('email', 'known@example.com')->first();

        $this->assertSame('Their own city', $artist->location);
        $this->assertSame('1.0,2.0', $artist->location_lat_long);
    }

    public function test_it_refuses_a_request_without_images(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/artists/onboard', $this->payload(['images' => []]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('images');
    }

    public function test_it_refuses_a_file_that_is_not_an_image(): void
    {
        $payload = $this->payload();
        $payload['images'][0]['mime'] = 'application/pdf';

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/admin/artists/onboard', $payload)
            ->assertStatus(422);
    }

    public function test_it_is_closed_to_non_admins(): void
    {
        $artist = User::factory()->asArtist()->create(['email_verified_at' => now()]);

        $this->actingAs($artist, 'sanctum')
            ->postJson('/api/admin/artists/onboard', $this->payload())
            ->assertForbidden();
    }

    private function payload(array $overrides = []): array
    {
        // 1x1 transparent PNG, small enough to keep the test honest about the
        // shape of the request without depending on a fixture file.
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

        return array_merge([
            'email' => 'newartist@example.com',
            'name' => 'New Artist',
            'images' => [
                ['content' => $png, 'mime' => 'image/png', 'filename' => 'one.png', 'size' => 70],
            ],
        ], $overrides);
    }
}
