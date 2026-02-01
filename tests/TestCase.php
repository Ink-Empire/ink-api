<?php

namespace Tests;

use App\Http\Middleware\VerifyAppToken;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable app token verification for all tests
        $this->withoutMiddleware(VerifyAppToken::class);
    }

    /**
     * Specify the migrations path for RefreshDatabase trait.
     * Uses the minimal testing migrations instead of full migrations.
     */
    protected function migrateFreshUsing()
    {
        return [
            '--path' => 'database/migrations/testing',
        ];
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
