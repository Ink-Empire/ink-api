# S3 buckets and environments

## Which bucket does my environment write to?

All of them. Local, CI and production write to the same bucket, `inked-in-images`.
There is no per-environment bucket.

The only separation is a prefix on the filename, set by `S3_FILE_PREFIX` and applied
by `ImageService::prefixFilename()`:

| Environment | `S3_FILE_PREFIX` | Example key                                    |
| ----------- | ---------------- | ---------------------------------------------- |
| local       | `local`          | `local-tattoo_412_20260101120000_ab12cd34.jpg` |
| test suite  | `test`           | `test-tattoo_412_20260101120000_ab12cd34.jpg`  |
| production  | `production`     | `production-tattoo_412_...jpg`                 |

That is a naming convention, not isolation. Your local credentials can read, write
and delete every object in the bucket, including production's.

## Why this matters

If you load a copy of the production database locally, every image row names a real
`production-*` object that exists in the shared bucket. Several paths delete the S3
object alongside the database row:

- `TattooService::deleteTattoo()`
- `CleanupDeletedUserJob`
- `DeleteImagesFromS3Job`
- `DeleteBulkUpload` and `BulkUpload::deleteZipFile()`
- `CleanupExpiredBulkUploads`
- `CleanupOrphanedS3Files`

Without a guard, deleting a tattoo on your laptop removes a real artist's image from
the live bucket, with no confirmation and nothing to undo it.

`CleanTestData` is safe. It only deletes database rows and never touches storage.
Keep it that way.

## The two protections

### 1. The test suite cannot reach S3

`Tests\TestCase::setUp()` calls `Storage::fake('s3')`, so every test in the suite
writes to a local directory under `storage/framework/testing/disks/s3`. A test that
exercises a delete path removes nothing real.

`phpunit.xml` also forces `S3_FILE_PREFIX=test`, so the prefix a test sees does not
depend on your `.env`.

If a test genuinely needs a real `S3Client` (`PresignedUploadTest` does, because the
fake adapter has no `getClient()`), override the disk in the file's own `beforeEach`.
That runs after `setUp`, so it wins. Point it at a bucket that does not exist.

`fixtures:export --upload` still uploads to the real bucket. It shells out to a
separate `php artisan test` process and uploads from the command process, which the
fake inside the suite does not touch.

### 2. The runtime guard

`App\Services\S3DeleteGuard` is installed as AWS SDK middleware on every S3 client the
application builds, via `App\Filesystem\GuardedFilesystemManager`. It runs in the init
step, before signing, so a refused delete never leaves the process.

It refuses `DeleteObject` and `DeleteObjects` for any key that does not belong to the
current environment:

- key starts with this environment's prefix — allowed
- key starts with another known environment's prefix — refused
- key has no environment prefix (written before prefixing existed) — allowed only in
  production, which is the only environment with legacy objects of its own
- key is under `bulk-uploads/` or `fixtures/` — allowed, see the gap below

Because middleware sits at the client, the guard also covers `tinker`, ad-hoc artisan
commands and anything calling `Storage::disk('s3')->getClient()` directly.

A refused delete is logged at `warning` with the key and the environment prefix. It is
not surfaced to the caller: Flysystem wraps the refusal in `UnableToDeleteFile` and the
s3 disk is configured with `throw => false`, so the application carries on and the
database row is still removed. The object survives, which is the point, but check the
log if a local delete looks like it worked and the image is still there.

### The escape hatch

`S3_ALLOW_CROSS_ENV_DELETES=true` disables the guard. It exists for a deliberate
cross-environment cleanup, such as running `cleanup:orphaned-s3-files --target-env=dev`
from somewhere that is not dev. Do not leave it set.

## Known gap: unprefixed namespaces

`bulk-uploads/{userId}/{id}_{random}.zip` and `fixtures/{branch}/...` carry no
environment prefix at all, so local and production keys are indistinguishable and the
guard cannot protect them. Deleting a bulk upload locally against a production database
copy will remove the real zip.

Closing this means prefixing the bulk-uploads folder, which orphans existing zips unless
they are migrated or read from both locations.

## If the bucket ever changes

Moving an environment to its own bucket is the real fix. The API reads the bucket from
`AWS_BUCKET` and builds public URLs from `AWS_URL` (currently a CloudFront distribution)
or the imgix domain, so nothing in the API hardcodes it.

The frontend never constructs S3 keys. It consumes the `uri` the API returns. Two places
name the bucket directly and would need updating:

- `nextjs/next.config.js` — `images.remotePatterns` lists
  `inked-in-images.s3.amazonaws.com` and `inked-in-images.s3.us-east-1.amazonaws.com`
- `nextjs/scripts/pull-fixtures.js` — `S3_BUCKET = 'inked-in-images'`

Note that a separate local bucket means local rows copied from production resolve to
objects that are not there, so every image on your local site 404s until the local
bucket is seeded or the rows are rewritten.
