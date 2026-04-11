<?php

namespace Tests\Unit\Jobs;

use App\Enums\UserTypes;
use App\Jobs\NotifyNearbyArtistsOfBeacon;
use App\Models\TattooLead;
use App\Models\User;
use App\Notifications\TattooBeaconNotification;
use App\Services\ArtistService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;
use Tests\Traits\RefreshTestDatabase;

class NotifyNearbyArtistsOfBeaconTest extends TestCase
{
    use MockeryPHPUnitIntegration;
    use RefreshTestDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeClient(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'type_id' => UserTypes::CLIENT_TYPE_ID,
        ], $overrides));
    }

    private function makeArtist(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'type_id' => UserTypes::ARTIST_TYPE_ID,
        ], $overrides));
    }

    private function makeLead(User $client, array $overrides = []): TattooLead
    {
        return TattooLead::create(array_merge([
            'user_id' => $client->id,
            'description' => 'Test request',
            'is_active' => true,
            'allow_artist_contact' => true,
            'lat' => 40.7128,
            'lng' => -74.0060,
            'radius' => 50,
            'radius_unit' => 'mi',
        ], $overrides));
    }

    public function test_returns_early_when_lead_not_found()
    {
        $job = new NotifyNearbyArtistsOfBeacon(99999);
        $service = Mockery::mock(ArtistService::class);
        $service->shouldNotReceive('getNearby');

        $job->handle($service);

        Notification::assertNothingSent();
    }

    public function test_returns_early_when_lead_inactive()
    {
        $client = $this->makeClient();
        $lead = $this->makeLead($client, ['is_active' => false]);

        $service = Mockery::mock(ArtistService::class);
        $service->shouldNotReceive('getNearby');

        (new NotifyNearbyArtistsOfBeacon($lead->id))->handle($service);

        Notification::assertNothingSent();
    }

    public function test_geo_path_calls_artist_service_with_lead_coordinates_and_distance()
    {
        $client = $this->makeClient();
        $lead = $this->makeLead($client, [
            'lat' => 40.7128,
            'lng' => -74.0060,
            'radius' => 25,
            'radius_unit' => 'mi',
        ]);

        $service = Mockery::mock(ArtistService::class);
        $service->shouldReceive('getNearby')
            ->once()
            ->with(40.7128, -74.0060, '25mi')
            ->andReturn(new Collection());

        (new NotifyNearbyArtistsOfBeacon($lead->id))->handle($service);
    }

    public function test_geo_path_passes_km_unit_when_radius_unit_is_km()
    {
        $client = $this->makeClient();
        $lead = $this->makeLead($client, [
            'radius' => 80,
            'radius_unit' => 'km',
        ]);

        $service = Mockery::mock(ArtistService::class);
        $service->shouldReceive('getNearby')
            ->once()
            ->with(Mockery::any(), Mockery::any(), '80km')
            ->andReturn(new Collection());

        (new NotifyNearbyArtistsOfBeacon($lead->id))->handle($service);
    }

    public function test_geo_path_uses_schema_default_radius_when_not_set()
    {
        $client = $this->makeClient();
        // tattoo_leads.radius defaults to 50 / radius_unit defaults to 'mi' at the schema level
        $lead = TattooLead::create([
            'user_id' => $client->id,
            'description' => 'Test',
            'is_active' => true,
            'allow_artist_contact' => true,
            'lat' => 40.7128,
            'lng' => -74.0060,
        ]);

        $service = Mockery::mock(ArtistService::class);
        $service->shouldReceive('getNearby')
            ->once()
            ->with(Mockery::any(), Mockery::any(), '50mi')
            ->andReturn(new Collection());

        (new NotifyNearbyArtistsOfBeacon($lead->id))->handle($service);
    }

    public function test_sends_beacon_notification_to_each_returned_artist()
    {
        $client = $this->makeClient();
        $lead = $this->makeLead($client);

        $artistA = $this->makeArtist();
        $artistB = $this->makeArtist();

        $service = Mockery::mock(ArtistService::class);
        $service->shouldReceive('getNearby')
            ->once()
            ->andReturn(new Collection([$artistA, $artistB]));

        (new NotifyNearbyArtistsOfBeacon($lead->id))->handle($service);

        Notification::assertSentTo($artistA, TattooBeaconNotification::class);
        Notification::assertSentTo($artistB, TattooBeaconNotification::class);
    }

    public function test_skips_self_when_returned_artist_is_the_client()
    {
        // Edge case: client is also an artist (type_id changed) and shows up in own results
        $clientWhoIsArtist = $this->makeArtist();
        $lead = $this->makeLead($clientWhoIsArtist);

        $service = Mockery::mock(ArtistService::class);
        $service->shouldReceive('getNearby')
            ->once()
            ->andReturn(new Collection([$clientWhoIsArtist]));

        (new NotifyNearbyArtistsOfBeacon($lead->id))->handle($service);

        Notification::assertNothingSent();
    }

    public function test_skips_artists_with_empty_email()
    {
        $client = $this->makeClient();
        $lead = $this->makeLead($client);

        $artistWithEmail = $this->makeArtist(['email' => 'real@example.com']);
        // Use empty string rather than null since users.email is NOT NULL.
        // The job's `if (!$artist->email)` check treats both as falsy.
        $artistEmptyEmail = $this->makeArtist(['email' => '']);

        $service = Mockery::mock(ArtistService::class);
        $service->shouldReceive('getNearby')
            ->once()
            ->andReturn(new Collection([$artistWithEmail, $artistEmptyEmail]));

        (new NotifyNearbyArtistsOfBeacon($lead->id))->handle($service);

        Notification::assertSentTo($artistWithEmail, TattooBeaconNotification::class);
        Notification::assertNotSentTo($artistEmptyEmail, TattooBeaconNotification::class);
    }

    public function test_fallback_path_uses_string_location_match_when_no_coordinates()
    {
        $client = $this->makeClient(['location' => 'Brooklyn, NY']);
        $lead = $this->makeLead($client, [
            'lat' => null,
            'lng' => null,
        ]);

        // Match by exact location and by city LIKE
        $matchExact = $this->makeArtist(['location' => 'Brooklyn, NY']);
        $matchCity = $this->makeArtist(['location' => 'Brooklyn Heights, NY']);
        $noMatch = $this->makeArtist(['location' => 'Austin, TX']);

        $service = Mockery::mock(ArtistService::class);
        $service->shouldNotReceive('getNearby');

        (new NotifyNearbyArtistsOfBeacon($lead->id))->handle($service);

        Notification::assertSentTo($matchExact, TattooBeaconNotification::class);
        Notification::assertSentTo($matchCity, TattooBeaconNotification::class);
        Notification::assertNotSentTo($noMatch, TattooBeaconNotification::class);
    }

    public function test_returns_early_when_no_artists_found()
    {
        $client = $this->makeClient();
        $lead = $this->makeLead($client);

        $service = Mockery::mock(ArtistService::class);
        $service->shouldReceive('getNearby')
            ->once()
            ->andReturn(new Collection());

        (new NotifyNearbyArtistsOfBeacon($lead->id))->handle($service);

        Notification::assertNothingSent();
    }
}
