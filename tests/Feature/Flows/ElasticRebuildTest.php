<?php

/**
 * Index rebuilds accept the model as a short name or a class.
 *
 * The admin panel and artisan commands pass short names like "Artist". Using
 * that verbatim as a class name threw "Class \"Artist\" not found", and the
 * failure was caught and logged, so rebuilds looked like they had run when
 * nothing had been indexed.
 */

use App\Models\Artist;
use App\Models\User;
use App\Services\ElasticService;

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

it('throws rather than silently succeeding for an unknown model', function () {
    // Documents current behaviour: rebuild() catches \Exception, but an
    // unresolvable class raises \Error, which escapes that catch.
    expect(fn () => $this->service->rebuild([$this->artist->id], 'NotARealModel'))
        ->toThrow(\Error::class);
});
