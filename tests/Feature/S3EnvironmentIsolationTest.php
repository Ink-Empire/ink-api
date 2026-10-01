<?php

use App\Models\Image;
use App\Models\Tattoo;
use App\Services\TattooService;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

/**
 * A real, guarded S3 disk whose HTTP layer records instead of calling AWS.
 * The credentials sign nothing that leaves the process and the bucket is not a
 * real one, so $requests is a complete account of what the suite would have
 * sent to S3.
 */
function recordingS3Disk(string $prefix, array &$requests): void
{
    config([
        'filesystems.disks.s3' => [
            'driver' => 's3',
            'key' => 'AKIAEXAMPLEEXAMPLE00',
            'secret' => 'example-secret-key-for-signing-only',
            'region' => 'us-east-1',
            'bucket' => 'test-bucket',
            'file_prefix' => $prefix,
            'http_handler' => function ($request) use (&$requests) {
                $requests[] = $request->getMethod().' '.$request->getUri()->getPath();

                return Create::promiseFor(new Response(200, [], ''));
            },
        ],
    ]);

    Storage::forgetDisk('s3');
}

function tattooWithImage(string $filename): array
{
    $tattoo = Tattoo::factory()->create();
    $image = Image::factory()->create(['filename' => $filename]);
    $tattoo->images()->attach($image->id);

    return [$tattoo->fresh(), $image];
}

test('the suite cannot reach s3 without a test opting in', function () {
    // No Storage::fake() in this file. The base TestCase does it, so any test
    // that exercises a delete path hits a local directory, not the bucket.
    $disk = Storage::disk('s3');

    expect($disk)->toBeInstanceOf(FilesystemAdapter::class);
    expect($disk->getAdapter())->not->toBeInstanceOf(League\Flysystem\AwsS3V3\AwsS3V3Adapter::class);
    expect($disk->path(''))->toContain('framework/testing/disks/s3');
});

test('deleting a tattoo does not delete an object belonging to another environment', function () {
    $requests = [];
    recordingS3Disk('local', $requests);

    $filename = 'production-tattoo_1_20260101000000_abcd1234.jpg';
    [$tattoo, $image] = tattooWithImage($filename);

    app(TattooService::class)->deleteTattoo($tattoo);

    // The existence check proves the delete branch was reached, so the absence
    // of a DELETE is the guard refusing rather than the code skipping the image.
    expect($requests)->toContain("HEAD /{$filename}");

    $deletes = array_filter($requests, fn ($r) => str_starts_with($r, 'DELETE '));
    expect($deletes)->toBeEmpty();

    // The refusal is not surfaced to the caller: Flysystem wraps it and the disk
    // is configured with throw => false, so the row still goes. The object, which
    // is the thing that cannot be recovered, survives.
    expect(Image::find($image->id))->toBeNull();
});

test('the legitimate production delete still reaches s3', function () {
    $requests = [];
    recordingS3Disk('production', $requests);

    $filename = 'production-tattoo_1_20260101000000_abcd1234.jpg';
    [$tattoo, $image] = tattooWithImage($filename);

    $deleted = app(TattooService::class)->deleteTattoo($tattoo);

    expect($deleted)->toBe(1);
    expect($requests)->toContain("DELETE /{$filename}");
    expect(Image::find($image->id))->toBeNull();
});

test('a bulk upload zip is still deletable from production', function () {
    $requests = [];
    recordingS3Disk('production', $requests);

    Storage::disk('s3')->delete('bulk-uploads/42/9_ab12cd34.zip');

    expect($requests)->toContain('DELETE /bulk-uploads/42/9_ab12cd34.zip');
});
