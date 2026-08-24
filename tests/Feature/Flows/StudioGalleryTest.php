<?php

/**
 * The studio gallery is the public shop page.
 *
 * It used to build its own Elasticsearch query instead of going through
 * TattooService, so it missed the visibility and demo rules every other tattoo
 * surface applies and it ignored the page parameter. A hidden, unapproved
 * tattoo was served publicly.
 */

use App\Enums\UserTypes;
use App\Http\Controllers\StudioController;
use App\Jobs\ReindexArtistAffiliationJob;
use App\Models\Studio;
use App\Models\User;
use App\Services\TattooService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Queue;

function galleryRequest(array $query = [], ?User $viewer = null): Request
{
    $request = Request::create('/studios/1/gallery', 'GET', $query);

    if ($viewer) {
        $request->setUserResolver(fn () => $viewer);
    }

    return $request;
}

it('asks TattooService for the gallery rather than building its own query', function () {
    $studio = Studio::factory()->create();

    $captured = null;
    $this->mock(TattooService::class, function ($mock) use (&$captured) {
        $mock->shouldReceive('getByStudio')->once()->andReturnUsing(function (...$args) use (&$captured) {
            $captured = $args;
            return ['response' => collect(), 'total' => 0];
        });
    });

    app(StudioController::class)->getGallery(galleryRequest(['limit' => 10]), $studio->id);

    expect($captured)->not->toBeNull()
        ->and($captured[0])->toBe($studio->id)
        ->and($captured[2]['per_page'])->toBe(10)
        ->and($captured[2]['is_demo'])->toBeFalse();
});

it('marks the viewer as a demo user so demo work stays visible to them', function () {
    $studio = Studio::factory()->create();
    $demoViewer = User::factory()->create(['is_demo' => true]);

    $captured = null;
    $this->mock(TattooService::class, function ($mock) use (&$captured) {
        $mock->shouldReceive('getByStudio')->once()->andReturnUsing(function (...$args) use (&$captured) {
            $captured = $args;
            return ['response' => collect(), 'total' => 0];
        });
    });

    app(StudioController::class)->getGallery(galleryRequest([], $demoViewer), $studio->id);

    expect($captured[2]['is_demo'])->toBeTrue();
});

it('counts a studio account owner among the gallery artists', function () {
    $owner = User::factory()->create(['type_id' => UserTypes::STUDIO_TYPE_ID]);
    $studio = Studio::factory()->create(['owner_id' => $owner->id]);

    $captured = null;
    $this->mock(TattooService::class, function ($mock) use (&$captured) {
        $mock->shouldReceive('getByStudio')->once()->andReturnUsing(function (...$args) use (&$captured) {
            $captured = $args;
            return ['response' => collect(), 'total' => 0];
        });
    });

    app(StudioController::class)->getGallery(galleryRequest(), $studio->id);

    expect($captured[1])->toContain($owner->id);
});

it('reports a real total and page in the gallery meta', function () {
    $studio = Studio::factory()->create();

    $this->mock(TattooService::class, function ($mock) {
        $mock->shouldReceive('getByStudio')->once()->andReturn(['response' => collect(), 'total' => 42]);
    });

    $response = app(StudioController::class)->getGallery(galleryRequest(['limit' => 10, 'page' => 2]), $studio->id);
    $meta = $response->getData(true)['meta'];

    expect($meta['total'])->toBe(42)
        ->and($meta['page'])->toBe(2)
        ->and($meta['per_page'])->toBe(10)
        ->and($meta['has_more'])->toBeTrue();
});

it('reindexes an artist when a studio verifies them', function () {
    Queue::fake();

    $owner = User::factory()->asArtist()->create();
    $studio = Studio::factory()->create(['owner_id' => $owner->id]);
    $artist = User::factory()->asArtist()->create();
    $studio->artists()->attach($artist->id, ['is_verified' => false, 'initiated_by' => 'artist']);

    app(StudioController::class)->verifyArtist(galleryRequest([], $owner), $studio->id, $artist->id);

    Queue::assertPushed(
        ReindexArtistAffiliationJob::class,
        fn ($job) => $job->artistId === $artist->id
    );
});

it('reindexes an artist when a studio removes their verification', function () {
    Queue::fake();

    $owner = User::factory()->asArtist()->create();
    $studio = Studio::factory()->create(['owner_id' => $owner->id]);
    $artist = User::factory()->asArtist()->create();
    $studio->artists()->attach($artist->id, ['is_verified' => true, 'initiated_by' => 'studio']);

    app(StudioController::class)->unverifyArtist(galleryRequest([], $owner), $studio->id, $artist->id);

    Queue::assertPushed(
        ReindexArtistAffiliationJob::class,
        fn ($job) => $job->artistId === $artist->id
    );
});
