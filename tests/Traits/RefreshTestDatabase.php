<?php

namespace Tests\Traits;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

trait RefreshTestDatabase
{
    use RefreshDatabase;

    /**
     * Run the testing-only migration in the same one-time phase as migrate:fresh,
     * before the per-test transaction opens.
     *
     * It previously ran from afterRefreshingDatabase(), which Laravel calls after
     * beginDatabaseTransaction(). MySQL implicitly commits on DDL, so that
     * committed the wrapping transaction and the first test of every run leaked
     * its writes into the test database instead of rolling back.
     */
    protected function refreshTestDatabase()
    {
        if (! RefreshDatabaseState::$migrated) {
            $this->artisan('migrate:fresh', $this->migrateFreshUsing());

            $this->artisan('migrate', [
                '--path' => 'database/migrations/testing/0007_create_artists_studios_if_missing.php',
                '--realpath' => false,
            ]);

            $this->app[Kernel::class]->setArtisan(null);

            RefreshDatabaseState::$migrated = true;
        }

        $this->beginDatabaseTransaction();
    }
}
