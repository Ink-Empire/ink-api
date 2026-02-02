<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        // CRITICAL: Check database BEFORE any migrations can run
        $this->assertNotProductionDatabase($app);

        return $app;
    }

    /**
     * Prevent tests from running against production database.
     * This check runs BEFORE RefreshDatabase can wipe anything.
     */
    protected function assertNotProductionDatabase(Application $app): void
    {
        $database = $app['config']['database.connections.mysql.database'];

        $forbiddenDatabases = ['inkedin', 'inkedin_prod', 'inkedin_production'];

        if (in_array($database, $forbiddenDatabases, true)) {
            // Use fwrite to ensure message appears even if PHPUnit output is buffered
            fwrite(STDERR, "\n\n");
            fwrite(STDERR, "╔══════════════════════════════════════════════════════════════════╗\n");
            fwrite(STDERR, "║  CRITICAL: REFUSING TO RUN TESTS                                 ║\n");
            fwrite(STDERR, "║                                                                  ║\n");
            fwrite(STDERR, "║  Connected to database: {$database}                              \n");
            fwrite(STDERR, "║  Tests MUST use 'inkedin_test' database.                         ║\n");
            fwrite(STDERR, "║                                                                  ║\n");
            fwrite(STDERR, "║  To fix, run: php artisan test --env=testing                     ║\n");
            fwrite(STDERR, "║  Or ensure .env.testing has DB_DATABASE=inkedin_test             ║\n");
            fwrite(STDERR, "╚══════════════════════════════════════════════════════════════════╝\n");
            fwrite(STDERR, "\n");

            exit(1);
        }
    }
}
