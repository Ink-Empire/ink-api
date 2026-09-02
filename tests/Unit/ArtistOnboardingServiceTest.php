<?php

namespace Tests\Unit;

use App\Enums\UserTypes;
use App\Models\User;
use App\Services\ArtistOnboardingService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\RefreshTestDatabase;

class ArtistOnboardingServiceTest extends TestCase
{
    use RefreshTestDatabase;

    private ArtistOnboardingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Notification::fake();

        $this->service = app(ArtistOnboardingService::class);
    }

    public function test_it_creates_a_provisional_artist_ready_to_claim(): void
    {
        [$artist, $tempPassword, $isNew] = $this->service->findOrCreateArtist('newartist@example.com', 'New Artist');

        $this->assertTrue($isNew);
        $this->assertSame(UserTypes::ARTIST_TYPE_ID, $artist->type_id);
        $this->assertTrue($artist->force_password_reset);
        $this->assertNotNull($artist->email_verified_at);
        $this->assertFalse((bool) $artist->has_accepted_toc);
        $this->assertNotNull($tempPassword);
    }

    /**
     * The plaintext password is only ever returned, so it can go in the one
     * email that hands it over. What lands in the database is the hash.
     */
    public function test_the_temp_password_works_and_is_not_stored_in_the_clear(): void
    {
        [$artist, $tempPassword] = $this->service->findOrCreateArtist('artist@example.com', 'Artist');

        $this->assertTrue(\Hash::check($tempPassword, $artist->password));
        $this->assertNotSame($tempPassword, $artist->password);
    }

    public function test_it_returns_the_existing_artist_without_a_new_password(): void
    {
        $existing = User::factory()->asArtist()->create(['email' => 'known@example.com']);

        [$artist, $tempPassword, $isNew] = $this->service->findOrCreateArtist('known@example.com', 'Ignored Name');

        $this->assertFalse($isNew);
        $this->assertSame($existing->id, $artist->id);
        $this->assertNull($tempPassword);
    }

    public function test_it_derives_a_username_from_the_address(): void
    {
        $this->assertSame('jane.doe', $this->service->generateUniqueUsername('Jane.Doe@example.com'));
        $this->assertSame('artist', $this->service->generateUniqueUsername('!!!@example.com'));
    }

    /**
     * Two artists whose addresses share a prefix must not collide, because
     * username and slug are both unique and the slug is the public page URL.
     */
    public function test_it_avoids_username_collisions(): void
    {
        User::factory()->asArtist()->create(['username' => 'taken', 'slug' => 'taken']);

        $this->assertSame('taken1', $this->service->generateUniqueUsername('taken@example.com'));
    }
}
