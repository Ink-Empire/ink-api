<?php

namespace Tests\Traits;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use PDO;
use RuntimeException;

trait RefreshTestDatabase
{
    use RefreshDatabase;

    /**
     * Connection holding the run's lock. Kept in a static so it outlives the
     * application instance, which is torn down between tests.
     */
    private static ?PDO $lockConnection = null;

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
            $this->acquireMigrationLock();

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

    /**
     * Claim the test database for this process.
     *
     * migrate:fresh drops every table, so two test runs sharing a database
     * destroy each other: one drops the schema while the other is still
     * migrating or querying. The failures surface far from the cause, usually
     * as a missing telescope_entries or migrations table, because those
     * migrations hold the widest window between statements.
     *
     * GET_LOCK is scoped to a connection, and Laravel rebuilds the application
     * between tests, so the framework's own connection cannot hold it for the
     * length of a run. The lock is taken on a dedicated PDO kept in a static
     * and is never released: MySQL frees it when the process exits, so a second
     * run waits for the first to finish instead of tearing out its schema.
     */
    private function acquireMigrationLock(): void
    {
        if (self::$lockConnection !== null) {
            return;
        }

        $config = config('database.connections.' . config('database.default'));

        if (($config['driver'] ?? null) !== 'mysql') {
            return;
        }

        $database = $config['database'];
        $timeout = (int) env('TEST_DB_LOCK_TIMEOUT', 300);

        $pdo = new PDO(
            "mysql:host={$config['host']};port={$config['port']}",
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $statement = $pdo->prepare('SELECT GET_LOCK(?, ?) AS acquired');
        $statement->execute(["test_db_migrate_{$database}", $timeout]);
        $acquired = (int) $statement->fetchColumn();

        if ($acquired !== 1) {
            throw new RuntimeException(
                "Timed out after {$timeout}s waiting for another test run to release {$database}. "
                . 'Tests share one database and each run rebuilds it from scratch, so they cannot '
                . 'overlap. Wait for the other run to finish, or raise TEST_DB_LOCK_TIMEOUT.'
            );
        }

        self::$lockConnection = $pdo;
    }
}
