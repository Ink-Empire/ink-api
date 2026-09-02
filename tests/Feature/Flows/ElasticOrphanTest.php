<?php

/**
 * Orphan scans decide what to delete out of a search index.
 *
 * The scan used the model's default query, so ArtistScope reported every
 * studio account in the artists index as an orphan. Deleting that set would
 * have emptied most of the index.
 *
 * These go through the route rather than calling the controller directly, so
 * the admin middleware and the endpoint's own validation are exercised too.
 */

use App\Enums\UserTypes;
use App\Models\User;
use App\Services\ElasticService;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // The user observer notifies Slack on create, which runs inline on the
    // sync queue and reaches out over the network.
    Queue::fake();

    $this->admin = User::factory()->create(['is_admin' => true, 'email_verified_at' => now()]);
});

function elasticServiceReturning(array $responses): ElasticService
{
    $service = Mockery::mock(ElasticService::class);

    $service->shouldReceive('post')->andReturnUsing(function ($path) use ($responses) {
        foreach ($responses as $fragment => $response) {
            if (str_contains($path, $fragment)) {
                return $response;
            }
        }

        return [];
    });

    return $service;
}

/**
 * Calls an admin elastic endpoint as an admin, with the search service
 * swapped for the given mock so no request reaches a real cluster.
 */
function adminElasticPost(ElasticService $service, string $path, array $params)
{
    app()->instance(ElasticService::class, $service);

    return test()->actingAs(test()->admin, 'sanctum')->postJson($path, $params);
}

it('does not treat studio accounts as orphans', function () {
    $artist = User::factory()->asArtist()->create(['email_verified_at' => now()]);
    $studioAccount = User::factory()->create([
        'type_id' => UserTypes::STUDIO_TYPE_ID,
        'email_verified_at' => now(),
    ]);

    $service = elasticServiceReturning([
        '_count' => ['count' => 2],
        '_search' => ['hits' => ['hits' => [
            ['_id' => (string) $artist->id],
            ['_id' => (string) $studioAccount->id],
        ]]],
    ]);

    $body = adminElasticPost($service, '/api/admin/elastic/find-orphans', ['model' => 'Artist'])->json();

    expect($body['orphan_count'])->toBe(0)
        ->and($body['db_total'])->toBe(2);
});

it('warns when most of the index looks orphaned', function () {
    $service = elasticServiceReturning([
        '_count' => ['count' => 3],
        '_search' => ['hits' => ['hits' => [
            ['_id' => '900001'],
            ['_id' => '900002'],
            ['_id' => '900003'],
        ]]],
    ]);

    $body = adminElasticPost($service, '/api/admin/elastic/find-orphans', ['model' => 'Artist'])->json();

    expect($body['orphan_count'])->toBe(3)
        ->and($body['warnings'])->not->toBeEmpty();
});

it('refuses to delete most of an index in one sweep', function () {
    $service = Mockery::mock(ElasticService::class);
    $service->shouldReceive('post')->with(Mockery::pattern('#_count#'), Mockery::any())->andReturn(['count' => 4]);
    $service->shouldNotReceive('post')->with(Mockery::pattern('#_delete_by_query#'), Mockery::any());

    $response = adminElasticPost($service, '/api/admin/elastic/delete-orphans', [
        'model' => 'Artist',
        'ids' => [900001, 900002, 900003],
    ]);

    expect($response->getStatusCode())->toBe(409)
        ->and($response->json('deleted'))->toBe(0);
});

it('skips ids that still have a database record', function () {
    $artist = User::factory()->asArtist()->create(['email_verified_at' => now()]);

    $service = Mockery::mock(ElasticService::class);
    $service->shouldNotReceive('post')->with(Mockery::pattern('#_delete_by_query#'), Mockery::any());

    $body = adminElasticPost($service, '/api/admin/elastic/delete-orphans', [
        'model' => 'Artist',
        'ids' => [$artist->id],
    ])->json();

    expect($body['deleted'])->toBe(0)
        ->and($body['skipped'])->toEqual([$artist->id]);
});

it('deletes a small orphan set', function () {
    $service = Mockery::mock(ElasticService::class);
    $service->shouldReceive('post')->with(Mockery::pattern('#_count#'), Mockery::any())->andReturn(['count' => 100]);
    $service->shouldReceive('post')->with(Mockery::pattern('#_delete_by_query#'), Mockery::any())->once()->andReturn(['deleted' => 1]);

    $body = adminElasticPost($service, '/api/admin/elastic/delete-orphans', [
        'model' => 'Artist',
        'ids' => [900001],
    ])->json();

    expect($body['deleted'])->toBe(1);
});

it('rejects an unknown model with a 422 instead of a class not found', function () {
    $service = Mockery::mock(ElasticService::class);
    $service->shouldNotReceive('post');

    $response = adminElasticPost($service, '/api/admin/elastic/find-orphans', ['model' => 'Sculpture']);

    expect($response->getStatusCode())->toBe(422)
        ->and($response->json('message'))->toContain('Sculpture');
});

it('rejects a real model that these endpoints do not index', function () {
    $service = Mockery::mock(ElasticService::class);
    $service->shouldNotReceive('post');

    // App\Models\Image exists but has no index behind it.
    $response = adminElasticPost($service, '/api/admin/elastic/find-orphans', ['model' => 'Image']);

    expect($response->getStatusCode())->toBe(422);
});

it('is closed to non admins', function () {
    $artist = User::factory()->asArtist()->create(['email_verified_at' => now()]);

    $this->actingAs($artist, 'sanctum')
        ->postJson('/api/admin/elastic/find-orphans', ['model' => 'Artist'])
        ->assertForbidden();
});
