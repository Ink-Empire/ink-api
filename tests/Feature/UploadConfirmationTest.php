<?php

use App\Enums\UploadPurpose;
use App\Models\Image;
use App\Models\User;
use App\Services\ImageService;
use Illuminate\Support\Facades\Storage;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

beforeEach(function () {
    Storage::fake('s3');
    config(['filesystems.disks.s3.file_prefix' => 'test']);

    $this->owner = User::factory()->create();
    $this->stranger = User::factory()->create();
});

/**
 * Put a file in the fake bucket under a filename issued to $user, the way a
 * presigned PUT would, and hand back the filename the client would confirm.
 */
function issueUpload(User $user, ?int $index = null): string
{
    $filename = ImageService::uploadFilename(UploadPurpose::Tattoo, $user->id, 'jpg', $index);

    Storage::disk('s3')->put($filename, 'fake-image-bytes');

    return $filename;
}

test('a user can confirm a file that was issued to them', function () {
    $filename = issueUpload($this->owner);

    $response = $this->actingAs($this->owner)
        ->postJson('/api/uploads/confirm', ['filenames' => [$filename]])
        ->assertOk();

    $response->assertJsonPath('data.count', 1);
    $response->assertJsonPath('data.images.0.filename', $filename);

    expect(Image::where('filename', $filename)->count())->toBe(1);
});

test('a user cannot confirm a file that was issued to someone else', function () {
    $filename = issueUpload($this->owner);

    $this->actingAs($this->stranger)
        ->postJson('/api/uploads/confirm', ['filenames' => [$filename]])
        ->assertStatus(400);

    expect(Image::where('filename', $filename)->exists())->toBeFalse();
});

test('one stolen filename in a batch does not cost the caller their own files', function () {
    $mine = issueUpload($this->stranger, 0);
    $theirs = issueUpload($this->owner, 0);

    $response = $this->actingAs($this->stranger)
        ->postJson('/api/uploads/confirm', ['filenames' => [$mine, $theirs]])
        ->assertOk();

    $response->assertJsonPath('data.count', 1);
    $response->assertJsonPath('data.images.0.filename', $mine);

    expect(Image::where('filename', $theirs)->exists())->toBeFalse();
});

test('a filename that is not in the bucket is still rejected the way it was', function () {
    $filename = ImageService::uploadFilename(UploadPurpose::Tattoo, $this->owner->id, 'jpg');

    $this->actingAs($this->owner)
        ->postJson('/api/uploads/confirm', ['filenames' => [$filename]])
        ->assertStatus(400);

    expect(Image::where('filename', $filename)->exists())->toBeFalse();
});

test('a filename that was never issued by the presign endpoints is rejected', function () {
    $filename = 'test-tattoo_not_a_user_id.jpg';
    Storage::disk('s3')->put($filename, 'fake-image-bytes');

    $this->actingAs($this->owner)
        ->postJson('/api/uploads/confirm', ['filenames' => [$filename]])
        ->assertStatus(400);

    expect(Image::where('filename', $filename)->exists())->toBeFalse();
});

test('confirming requires authentication', function () {
    $filename = issueUpload($this->owner);

    $this->postJson('/api/uploads/confirm', ['filenames' => [$filename]])
        ->assertStatus(401);

    expect(Image::where('filename', $filename)->exists())->toBeFalse();
});

test('every filename the presign endpoints issue passes its own ownership check', function (UploadPurpose $purpose, ?int $index) {
    $filename = ImageService::uploadFilename($purpose, $this->owner->id, 'jpg', $index);

    expect(ImageService::filenameBelongsToUser($filename, $this->owner->id))->toBeTrue();
    expect(ImageService::filenameBelongsToUser($filename, $this->stranger->id))->toBeFalse();
})->with(function () {
    foreach (UploadPurpose::cases() as $purpose) {
        yield [$purpose, null];
        yield [$purpose, 0];
    }
});

test('the ownership check reads filenames stored without an environment prefix', function () {
    config(['filesystems.disks.s3.file_prefix' => '']);

    $filename = ImageService::uploadFilename(UploadPurpose::Tattoo, $this->owner->id, 'jpg');

    expect($filename)->toStartWith('tattoo_');
    expect(ImageService::ownerIdFromFilename($filename))->toBe($this->owner->id);
});

test('a filename cannot borrow another user id by prefixing itself', function () {
    expect(ImageService::ownerIdFromFilename("uploads/test-tattoo_{$this->owner->id}_20260101000000_ab.jpg"))
        ->toBeNull();

    expect(ImageService::ownerIdFromFilename("test-mytattoo_{$this->owner->id}_20260101000000_ab.jpg"))
        ->toBeNull();
});
