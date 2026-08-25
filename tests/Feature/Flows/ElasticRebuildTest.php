<?php

/**
 * Index rebuilds accept the model as a short name or a class.
 *
 * The admin panel and artisan commands pass short names like "Artist". Using
 * that verbatim as a class name threw "Class \"Artist\" not found", and the
 * failure was caught and logged, so rebuilds looked like they had run when
 * nothing had been indexed.
 */

use App\Enums\UserTypes;
use App\Models\Artist;
use App\Models\User;
use App\Services\ElasticService;
use Larelastic\Elastic\Facades\Elastic;

beforeEach(function () {
    $this->artist = User::factory()->asArtist()->create(['email_verified_at' => now()]);
    $this->service = new ElasticService();
});

it('rebuilds when given a short model name', function () {
    $result = $this->service->rebuild([$this->artist->id], 'Artist');

    expect($result['status'])->toBeTrue()
        ->and($result['message'] ?? '')->not->toContain('not found');
});

it('rebuilds when given a fully qualified class name', function () {
    $result = $this->service->rebuild([$this->artist->id], Artist::class);

    expect($result['status'])->toBeTrue();
});

it('rebuilds when given a model instance', function () {
    $result = $this->service->rebuild([$this->artist->id], Artist::find($this->artist->id));

    expect($result['status'])->toBeTrue();
});

it('reports a failure rather than blowing up on an unknown model', function () {
    // An unresolvable name used to raise \Error, which escaped the catch and
    // reached the browser as a 500.
    $result = $this->service->rebuild([$this->artist->id], 'NotARealModel');

    expect($result['status'])->toBeFalse()
        ->and($result['message'])->toContain('NotARealModel');
});

it('indexes studio accounts, which the artist scope hides', function () {
    $studioAccount = User::factory()->create([
        'type_id' => UserTypes::STUDIO_TYPE_ID,
        'email_verified_at' => now(),
    ]);

    // The global scope pins Artist to type_id = 2, but the artists index is
    // built from searchableQuery(), which includes studio accounts.
    expect(Artist::find($studioAccount->id))->toBeNull();

    $result = $this->service->rebuild([$studioAccount->id], 'Artist');

    expect($result['indexed'])->toBe(1)
        ->and($result['missing_ids'])->toBeEmpty();
});

it('reports how many records it indexed', function () {
    Elastic::swap(Mockery::mock()->shouldReceive('delete')->once()->getMock());

    $result = $this->service->rebuild([$this->artist->id, 999999], 'Artist');

    expect($result['requested'])->toBe(2)
        ->and($result['indexed'])->toBe(1)
        ->and($result['missing_ids'])->toEqual([999999]);
});

it('deletes missing ids from the model index, not the default index', function () {
    // The default index is tattoos, so an artist rebuild used to delete the
    // tattoo that happened to share the id.
    Elastic::swap(
        Mockery::mock()
            ->shouldReceive('delete')
            ->once()
            ->withArgs(fn ($params) => $params['index'] === 'artists' && (int) $params['id'] === 999999)
            ->getMock()
    );

    $result = $this->service->rebuild([999999], 'Artist');

    expect($result['removed'])->toBe(1);
});
