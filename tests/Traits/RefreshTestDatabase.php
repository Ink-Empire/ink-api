<?php

namespace Tests\Traits;

use Illuminate\Foundation\Testing\RefreshDatabase;

trait RefreshTestDatabase
{
    use RefreshDatabase;

    protected function afterRefreshingDatabase(): void
    {
        $this->artisan('migrate', [
            '--path' => 'database/migrations/testing/0007_create_artists_studios_if_missing.php',
            '--realpath' => false,
        ]);
    }
}
