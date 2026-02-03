<?php

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

trait RefreshTestDatabase
{
    use RefreshDatabase;

    /**
     * Use the minimal testing migrations instead of full migrations.
     * This avoids migration ordering issues in the main migrations folder.
     */
    protected function migrateFreshUsing()
    {
        return [
            '--path' => 'database/migrations/testing',
            '--realpath' => false,
        ];
    }
}
