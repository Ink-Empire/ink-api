<?php

use App\Enums\UploadPurpose;
use App\Services\ImageService;
use App\Services\S3DeleteGuard;

/**
 * The decision table. Every environment shares one bucket, so the guard's job
 * is to tell "mine" from "somebody else's" using the filename prefix alone.
 */
beforeEach(function () {
    config(['filesystems.disks.s3.file_prefix' => 'local']);
});

test('an object carrying this environment prefix can be deleted', function () {
    expect(S3DeleteGuard::allows('local-tattoo_1_20260101000000_abcd1234.jpg'))->toBeTrue();
});

test('an object carrying another environment prefix cannot be deleted', function () {
    expect(S3DeleteGuard::allows('production-tattoo_1_20260101000000_abcd1234.jpg'))->toBeFalse();
    expect(S3DeleteGuard::allows('staging-tattoo_1_20260101000000_abcd1234.jpg'))->toBeFalse();
});

test('a leading slash does not get a foreign object past the guard', function () {
    expect(S3DeleteGuard::allows('/production-tattoo_1_20260101000000_abcd1234.jpg'))->toBeFalse();
});

test('an unprefixed legacy object cannot be deleted outside production', function () {
    expect(S3DeleteGuard::allows('tattoo_1_20260101000000_abcd1234.jpg'))->toBeFalse();
});

test('production can delete its own unprefixed legacy objects', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['filesystems.disks.s3.file_prefix' => 'production']);

    expect(S3DeleteGuard::allows('tattoo_1_20260101000000_abcd1234.jpg'))->toBeTrue();
    expect(S3DeleteGuard::allows('production-tattoo_1_20260101000000_abcd1234.jpg'))->toBeTrue();
});

test('production still cannot delete another environment object', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['filesystems.disks.s3.file_prefix' => 'production']);

    expect(S3DeleteGuard::allows('local-tattoo_1_20260101000000_abcd1234.jpg'))->toBeFalse();
});

test('namespaces written without a prefix are not gated', function () {
    // bulk-uploads/ and fixtures/ keys carry no environment marker at all, so
    // the guard cannot tell them apart and must let them through.
    expect(S3DeleteGuard::allows('bulk-uploads/42/9_ab12cd34.zip'))->toBeTrue();
    expect(S3DeleteGuard::allows('fixtures/develop/artist.json'))->toBeTrue();
});

test('the escape hatch allows a deliberate cross environment cleanup', function () {
    config(['filesystems.disks.s3.allow_cross_env_deletes' => true]);

    expect(S3DeleteGuard::allows('production-tattoo_1_20260101000000_abcd1234.jpg'))->toBeTrue();
});

test('every filename shape production generates passes its own guard', function () {
    app()->detectEnvironment(fn () => 'production');
    config(['filesystems.disks.s3.file_prefix' => 'production']);

    $keys = [
        ImageService::uploadFilename(UploadPurpose::Tattoo, 7, 'jpg'),
        ImageService::uploadFilename(UploadPurpose::Tattoo, 7, 'jpg', 3),
        ImageService::prefixFilename('tattoo_7_20260101000000_11_abcd1234.jpg'),
        ImageService::prefixFilename('watermarked_7_'.date('Ymdhis').'.jpg'),
        'bulk-uploads/7/9_ab12cd34.zip',
    ];

    foreach ($keys as $key) {
        expect(S3DeleteGuard::allows($key))->toBeTrue("blocked a legitimate production key: {$key}");
    }
});

test('assertDeletable throws rather than returning false', function () {
    S3DeleteGuard::assertDeletable('production-tattoo_1_20260101000000_abcd1234.jpg');
})->throws(App\Exceptions\CrossEnvironmentDeleteException::class);
