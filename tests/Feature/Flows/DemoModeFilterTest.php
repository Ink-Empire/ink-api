<?php

/**
 * The demo toggle is the only thing that decides whether demo content shows.
 *
 * It used to be overridden by the viewer's own is_demo column, so any account
 * carrying that flag saw demo artists everywhere with a toggle that did
 * nothing. 56 accounts were in that state, because the frontend decides who is
 * a demo account from their slug and the backend decided from the column.
 */

use App\Http\Controllers\ArtistController;
use App\Models\User;
use App\Services\ArtistService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

function artistSearchParams(array $query, ?User $viewer = null): array
{
    Cache::flush();

    $captured = [];

    test()->mock(ArtistService::class, function ($mock) use (&$captured) {
        $mock->shouldReceive('search')->once()->andReturnUsing(function ($params) use (&$captured) {
            $captured = $params;
            return ['response' => collect(), 'total' => 0];
        });
    });

    $request = Request::create('/api/artists', 'GET', $query);

    if ($viewer) {
        $request->setUserResolver(fn () => $viewer);
    }

    app(ArtistController::class)->search($request);

    return $captured;
}

it('hides demo content from a flagged account that has not toggled it on', function () {
    $flaggedViewer = User::factory()->asArtist()->create(['is_demo' => true]);

    $params = artistSearchParams(['useAnyLocation' => true], $flaggedViewer);

    expect($params['include_demo'])->toBeFalse();
});

it('shows demo content to a flagged account that toggled it on', function () {
    $flaggedViewer = User::factory()->asArtist()->create(['is_demo' => true]);

    $params = artistSearchParams(['useAnyLocation' => true, 'include_demo' => 1], $flaggedViewer);

    expect($params['include_demo'])->toBeTrue();
});

it('lets a plain account toggle demo content on', function () {
    $viewer = User::factory()->asArtist()->create(['is_demo' => false]);

    expect(artistSearchParams(['useAnyLocation' => true], $viewer)['include_demo'])->toBeFalse();
    expect(artistSearchParams(['useAnyLocation' => true, 'include_demo' => 1], $viewer)['include_demo'])->toBeTrue();
});

it('hides demo content from an anonymous visitor', function () {
    expect(artistSearchParams(['useAnyLocation' => true])['include_demo'])->toBeFalse();
});

it('filters demo records out of the query unless the toggle is on', function () {
    $service = app(ArtistService::class);

    $reflection = new ReflectionClass($service);

    $filters = $reflection->getProperty('filters');
    $filters->setAccessible(true);

    $search = $reflection->getProperty('search');
    $search->setAccessible(true);

    $applyCommonFilters = $reflection->getMethod('applyCommonFilters');
    $applyCommonFilters->setAccessible(true);

    $initializeSearch = $reflection->getMethod('initializeSearch');
    $initializeSearch->setAccessible(true);

    foreach ([false => true, true => false] as $includeDemo => $expectsFilter) {
        $filters->setValue($service, ['include_demo' => $includeDemo]);
        $initializeSearch->invoke($service);
        $applyCommonFilters->invoke($service);

        $query = json_encode($search->getValue($service)->bool ?? []);

        expect(str_contains($query, 'is_demo'))->toBe($expectsFilter);
    }
});
