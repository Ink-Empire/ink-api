<?php

use App\Enums\UploadPurpose;
use App\Models\User;
use App\Services\ImageService;
use Illuminate\Support\Facades\Storage;
use Tests\Traits\RefreshTestDatabase;

uses(RefreshTestDatabase::class);

beforeEach(function () {
    // A real S3Client with static credentials. Signing a POST policy is local
    // HMAC with no request to AWS, and the bucket here is not a real one, so
    // nothing in this file can touch storage. Storage::fake is no use because
    // its adapter has no getClient().
    config([
        'filesystems.disks.s3' => [
            'driver' => 's3',
            'key' => 'AKIAEXAMPLEEXAMPLE00',
            'secret' => 'example-secret-key-for-signing-only',
            'region' => 'us-east-1',
            'bucket' => 'test-bucket',
            'file_prefix' => 'test',
        ],
        'uploads.max_image_size_mb' => 25,
        'uploads.window_minutes' => 15,
    ]);

    Storage::forgetDisk('s3');

    $this->user = User::factory()->create();
});

/**
 * The signed policy S3 will check the upload against.
 */
function uploadPolicy(array $upload): array
{
    return json_decode(base64_decode($upload['fields']['Policy']), true);
}

/**
 * The single condition matching $name, or null. Conditions are a mixed bag of
 * ["eq", "$field", value] triples and {field: value} pairs.
 */
function policyCondition(array $policy, string $name): ?array
{
    foreach ($policy['conditions'] as $condition) {
        if (isset($condition[0]) && $condition[0] === $name) {
            return $condition;
        }

        if (isset($condition[0], $condition[1]) && $condition[0] === 'eq' && $condition[1] === '$' . $name) {
            return $condition;
        }
    }

    return null;
}

test('the upload policy bounds the size of the object that can be written', function () {
    $upload = app(ImageService::class)->uploadForm(UploadPurpose::Tattoo, $this->user->id, 'image/jpeg');

    $range = policyCondition(uploadPolicy($upload), 'content-length-range');

    expect($range)->not->toBeNull()
        ->and($range[1])->toBe(1)
        ->and($range[2])->toBe(25 * 1024 * 1024);
});

test('the size limit follows the configured value', function () {
    config(['uploads.max_image_size_mb' => 4]);

    $upload = app(ImageService::class)->uploadForm(UploadPurpose::Tattoo, $this->user->id, 'image/jpeg');

    $range = policyCondition(uploadPolicy($upload), 'content-length-range');

    expect($range[2])->toBe(4 * 1024 * 1024);
});

test('the policy pins the key, content type and acl so the client cannot choose them', function () {
    $upload = app(ImageService::class)->uploadForm(UploadPurpose::Profile, $this->user->id, 'image/png');

    $policy = uploadPolicy($upload);

    expect(policyCondition($policy, 'key')[2])->toBe($upload['filename'])
        ->and(policyCondition($policy, 'Content-Type')[2])->toBe('image/png')
        ->and(policyCondition($policy, 'acl')[2])->toBe('public-read');

    expect($policy['conditions'])->toContain(['bucket' => 'test-bucket']);
});

test('every field the client posts is covered by a condition', function () {
    $upload = app(ImageService::class)->uploadForm(UploadPurpose::Tattoo, $this->user->id, 'image/webp');

    $policy = uploadPolicy($upload);

    // S3 rejects a POST carrying a field the policy does not mention. These
    // are the only fields exempt from that rule.
    $exempt = ['Policy', 'X-Amz-Signature', 'X-Amz-Algorithm', 'X-Amz-Credential', 'X-Amz-Date'];

    foreach (array_keys($upload['fields']) as $field) {
        if (in_array($field, $exempt, true)) {
            continue;
        }

        expect(policyCondition($policy, $field))->not->toBeNull("field {$field} has no condition");
    }
});

test('the presign endpoint returns a form and the size limit', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/uploads/presign', [
            'content_type' => 'image/jpeg',
            'purpose' => 'tattoo',
        ])
        ->assertOk();

    $response->assertJsonStructure([
        'data' => ['upload_url', 'fields', 'filename', 'public_url', 'max_bytes', 'expires_in'],
    ]);

    $response->assertJsonPath('data.max_bytes', 25 * 1024 * 1024);
    $response->assertJsonPath('data.expires_in', 900);

    $range = policyCondition(uploadPolicy($response->json('data')), 'content-length-range');
    expect($range[2])->toBe(25 * 1024 * 1024);
});

test('the batch endpoint bounds every form it issues', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/uploads/presign-batch', [
            'purpose' => 'tattoo',
            'files' => [
                ['content_type' => 'image/jpeg'],
                ['content_type' => 'image/png'],
                ['content_type' => 'image/gif'],
            ],
        ])
        ->assertOk();

    $uploads = $response->json('data.uploads');

    expect($uploads)->toHaveCount(3);

    foreach ($uploads as $upload) {
        $range = policyCondition(uploadPolicy($upload), 'content-length-range');
        expect($range[2])->toBe(25 * 1024 * 1024);
    }

    $filenames = array_column($uploads, 'filename');
    expect($filenames)->toBe(array_unique($filenames));
});

test('the issued key still carries the caller so confirmation can check it', function () {
    $response = $this->actingAs($this->user)
        ->postJson('/api/uploads/presign', [
            'content_type' => 'image/jpeg',
            'purpose' => 'tattoo',
        ])
        ->assertOk();

    $filename = $response->json('data.filename');

    expect(ImageService::filenameBelongsToUser($filename, $this->user->id))->toBeTrue();
});

test('an unauthenticated caller gets no upload form', function () {
    $this->postJson('/api/uploads/presign', [
        'content_type' => 'image/jpeg',
        'purpose' => 'tattoo',
    ])->assertStatus(401);
});
