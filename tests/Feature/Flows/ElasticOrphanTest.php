<?php

/**
 * Orphan scans decide what to delete out of a search index.
 *
 * The scan used the model's default query, so ArtistScope reported every
 * studio account in the artists index as an orphan. Deleting that set would
 * have emptied most of the index.
 */

use App\Enums\UserTypes;
use App\Http\Controllers\ElasticController;
use App\Models\User;
use App\Services\ElasticService;
use Illuminate\Http\Request;

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

function orphanRequest(array $params): Request
{
    return Request::create('/admin/elastic/find-orphans', 'POST', $params);
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

    $response = (new ElasticController($service))->findOrphans(orphanRequest(['model' => 'Artist']));
    $body = $response->getData(true);

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

    $response = (new ElasticController($service))->findOrphans(orphanRequest(['model' => 'Artist']));
    $body = $response->getData(true);

    expect($body['orphan_count'])->toBe(3)
        ->and($body['warnings'])->not->toBeEmpty();
});

it('refuses to delete most of an index in one sweep', function () {
    $service = Mockery::mock(ElasticService::class);
    $service->shouldReceive('post')->with(Mockery::pattern('#_count#'), Mockery::any())->andReturn(['count' => 4]);
    $service->shouldNotReceive('post')->with(Mockery::pattern('#_delete_by_query#'), Mockery::any());

    $request = Request::create('/admin/elastic/delete-orphans', 'POST', [
        'model' => 'Artist',
        'ids' => [900001, 900002, 900003],
    ]);

    $response = (new ElasticController($service))->deleteOrphans($request);

    expect($response->getStatusCode())->toBe(409)
        ->and($response->getData(true)['deleted'])->toBe(0);
});

it('skips ids that still have a database record', function () {
    $artist = User::factory()->asArtist()->create(['email_verified_at' => now()]);

    $service = Mockery::mock(ElasticService::class);
    $service->shouldNotReceive('post')->with(Mockery::pattern('#_delete_by_query#'), Mockery::any());

    $request = Request::create('/admin/elastic/delete-orphans', 'POST', [
        'model' => 'Artist',
        'ids' => [$artist->id],
    ]);

    $response = (new ElasticController($service))->deleteOrphans($request);
    $body = $response->getData(true);

    expect($body['deleted'])->toBe(0)
        ->and($body['skipped'])->toEqual([$artist->id]);
});

it('deletes a small orphan set', function () {
    $service = Mockery::mock(ElasticService::class);
    $service->shouldReceive('post')->with(Mockery::pattern('#_count#'), Mockery::any())->andReturn(['count' => 100]);
    $service->shouldReceive('post')->with(Mockery::pattern('#_delete_by_query#'), Mockery::any())->once()->andReturn(['deleted' => 1]);

    $request = Request::create('/admin/elastic/delete-orphans', 'POST', [
        'model' => 'Artist',
        'ids' => [900001],
    ]);

    $response = (new ElasticController($service))->deleteOrphans($request);

    expect($response->getData(true)['deleted'])->toBe(1);
});
