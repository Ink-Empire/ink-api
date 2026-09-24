<?php

use App\Models\BulkUpload;
use App\Models\Image;
use App\Models\User;
use App\Services\ArtistOnboardingService;
use App\Services\ImageService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

// 1x1 pixels, small enough to inline and real enough to parse.
const PNG_BYTES = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
const GIF_BYTES = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

beforeEach(function () {
    Storage::fake('s3');
    config(['filesystems.disks.s3.file_prefix' => 'test']);

    $this->service = new ImageService();
});

test('real image bytes are stored', function () {
    $image = $this->service->processImage(PNG_BYTES, 'tattoo_1_20260101.png');

    expect($image)->toBeInstanceOf(Image::class);
    expect(Storage::disk('s3')->exists('test-tattoo_1_20260101.png'))->toBeTrue();
    expect(Image::where('filename', 'test-tattoo_1_20260101.png')->count())->toBe(1);
});

test('a data uri is stored once the prefix is stripped', function () {
    $image = $this->service->processImage(
        'data:image/png;base64,' . PNG_BYTES,
        'tattoo_1_20260101.png'
    );

    expect($image)->toBeInstanceOf(Image::class);
    expect(Storage::disk('s3')->get('test-tattoo_1_20260101.png'))->toBe(base64_decode(PNG_BYTES));
});

test('bytes that are not an image are rejected', function () {
    expect(fn () => $this->service->processImage(base64_encode('not an image at all'), 'tattoo_1.jpg'))
        ->toThrow(Exception::class);

    expect(Storage::disk('s3')->exists('test-tattoo_1.jpg'))->toBeFalse();
    expect(Image::count())->toBe(0);
});

test('an uploaded file that is not an image is rejected', function () {
    $file = UploadedFile::fake()->createWithContent('portfolio.jpg', '<?php echo "hi";');

    expect(fn () => $this->service->processImage($file, 'tattoo_1.jpg'))
        ->toThrow(Exception::class);

    expect(Image::count())->toBe(0);
});

test('empty input is rejected', function () {
    expect(fn () => $this->service->processImage('', 'tattoo_1.jpg'))
        ->toThrow(Exception::class);

    expect(Image::count())->toBe(0);
});

test('the content type comes from the bytes, not from what the client declared', function () {
    $captured = null;

    $disk = Mockery::mock(Illuminate\Contracts\Filesystem\Filesystem::class);
    $disk->shouldReceive('put')
        ->once()
        ->andReturnUsing(function ($filename, $contents, $options) use (&$captured) {
            $captured = $options;
            return true;
        });

    Storage::shouldReceive('disk')->with('s3')->andReturn($disk);

    // The prefix claims JPEG. The bytes are a PNG.
    (new ImageService())->processImage(
        'data:image/jpeg;base64,' . PNG_BYTES,
        'tattoo_1_20260101.jpg'
    );

    expect($captured['ContentType'])->toBe('image/png');
});

test('a declared type that disagrees with the bytes does not stop the upload', function () {
    $image = $this->service->processImage(
        'data:image/jpeg;base64,' . GIF_BYTES,
        'tattoo_1_20260101.jpg'
    );

    expect($image)->toBeInstanceOf(Image::class);
    expect(Storage::disk('s3')->get('test-tattoo_1_20260101.jpg'))->toBe(base64_decode(GIF_BYTES));
});

test('one bad file in a batch leaves the rest to succeed', function () {
    $artist = User::factory()->create();

    $bulkUpload = app(ArtistOnboardingService::class)->ingestImages($artist, [
        ['content' => PNG_BYTES, 'mime' => 'image/png', 'filename' => 'good-one.png', 'size' => 70],
        ['content' => base64_encode('this is not an image'), 'mime' => 'image/png', 'filename' => 'bad.png', 'size' => 20],
        ['content' => GIF_BYTES, 'mime' => 'image/gif', 'filename' => 'good-two.gif', 'size' => 43],
    ], 'email');

    expect($bulkUpload)->toBeInstanceOf(BulkUpload::class);
    expect($bulkUpload->fresh()->status)->toBe('ready');
    expect($bulkUpload->items()->count())->toBe(2);
    expect(Image::count())->toBe(2);
});

test('a batch of nothing but bad files is marked failed', function () {
    $artist = User::factory()->create();

    $bulkUpload = app(ArtistOnboardingService::class)->ingestImages($artist, [
        ['content' => base64_encode('not an image'), 'mime' => 'image/png', 'filename' => 'bad.png', 'size' => 12],
    ], 'email');

    expect($bulkUpload->fresh()->status)->toBe('failed');
    expect(Image::count())->toBe(0);
});
