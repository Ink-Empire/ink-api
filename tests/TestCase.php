<?php

namespace Tests;

use App\Http\Middleware\VerifyAppToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Local, test and production all write to the same S3 bucket, separated
        // only by a filename prefix. Without this a test that exercises a delete
        // path removes a real production object. Faking the disk here makes the
        // whole suite incapable of reaching S3; a test that needs a real client
        // overrides the disk in its own beforeEach, which runs after this.
        // See docs/s3-environments.md.
        Storage::fake('s3');

        // Disable app token verification for all tests
        $this->withoutMiddleware(VerifyAppToken::class);
    }

    /**
     * Export a JSON fixture for use in frontend tests.
     * Only exports when EXPORT_FIXTURES=true environment variable is set.
     */
    protected function exportFixture(string $filename, array $data): void
    {
        if (!env('EXPORT_FIXTURES', false)) {
            return;
        }

        $path = base_path("tests/fixtures/{$filename}");
        $dir = dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
