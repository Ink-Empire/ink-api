<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration refactors studio affiliations:
     * - Removes studio_id from users table (deprecated)
     * - Adds is_primary to users_studios pivot for designating primary studio
     * - Migrates all existing studio_id data to the pivot table
     */
    public function up()
    {
        // Check if migration already ran (is_primary column exists)
        if (Schema::hasColumn('users_studios', 'is_primary')) {
            Log::info("Migration: is_primary column already exists, skipping migration");
            return;
        }

        // Check if studio_id column exists on users table
        if (!Schema::hasColumn('users', 'studio_id')) {
            Log::info("Migration: studio_id column doesn't exist, skipping migration");
            return;
        }

        // Count users with studio_id that need migration
        $usersWithStudioId = DB::table('users')
            ->whereNotNull('studio_id')
            ->count();

        Log::info("Migration: Found {$usersWithStudioId} users with studio_id to migrate");

        // First, add is_primary column to users_studios
        Schema::table('users_studios', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)->after('initiated_by');
        });

        // Step 1: For users with studio_id who DON'T have a pivot record yet, create one
        $inserted = DB::statement("
            INSERT INTO users_studios (user_id, studio_id, is_verified, is_primary, initiated_by, created_at, updated_at)
            SELECT u.id, u.studio_id, 1, 1, 'artist', NOW(), NOW()
            FROM users u
            WHERE u.studio_id IS NOT NULL
            AND NOT EXISTS (
                SELECT 1 FROM users_studios us
                WHERE us.user_id = u.id AND us.studio_id = u.studio_id
            )
        ");

        // Step 2: For users with studio_id who DO have a pivot record, update it
        // Set is_verified = 1 and is_primary = 1 (the studio_id column represents a verified relationship)
        DB::statement("
            UPDATE users_studios us
            INNER JOIN users u ON us.user_id = u.id AND us.studio_id = u.studio_id
            SET us.is_verified = 1, us.is_primary = 1
            WHERE u.studio_id IS NOT NULL
        ");

        // Verify migration: count users whose studio_id is now in the pivot table
        $migratedCount = DB::table('users')
            ->join('users_studios', function ($join) {
                $join->on('users.id', '=', 'users_studios.user_id')
                     ->on('users.studio_id', '=', 'users_studios.studio_id');
            })
            ->whereNotNull('users.studio_id')
            ->where('users_studios.is_primary', 1)
            ->count();

        Log::info("Migration: Migrated {$migratedCount} of {$usersWithStudioId} studio affiliations");

        // Safety check: don't drop column if migration failed
        if ($usersWithStudioId > 0 && $migratedCount < $usersWithStudioId) {
            $missing = $usersWithStudioId - $migratedCount;
            Log::error("Migration: {$missing} users were not migrated! Aborting column drop.");

            // Get the user IDs that weren't migrated for debugging
            $notMigrated = DB::table('users')
                ->leftJoin('users_studios', function ($join) {
                    $join->on('users.id', '=', 'users_studios.user_id')
                         ->on('users.studio_id', '=', 'users_studios.studio_id');
                })
                ->whereNotNull('users.studio_id')
                ->whereNull('users_studios.id')
                ->pluck('users.id');

            Log::error("Migration: Users not migrated: " . $notMigrated->implode(', '));

            throw new \Exception("Studio affiliation migration incomplete. {$missing} users were not migrated. Check logs for details.");
        }

        // Remove the studio_id foreign key and column from users
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['studio_id']);
            $table->dropColumn('studio_id');
        });

        Log::info("Migration: Successfully removed studio_id column from users table");
    }

    /**
     * Reverse the migrations.
     */
    public function down()
    {
        // Re-add studio_id to users table if it doesn't exist
        if (!Schema::hasColumn('users', 'studio_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('studio_id')->nullable()->after('address_id')->constrained();
            });

            // Restore studio_id from primary studio in pivot
            DB::statement("
                UPDATE users u
                INNER JOIN users_studios us ON u.id = us.user_id AND us.is_primary = 1 AND us.is_verified = 1
                SET u.studio_id = us.studio_id
            ");
        }

        // Remove is_primary column if it exists
        if (Schema::hasColumn('users_studios', 'is_primary')) {
            Schema::table('users_studios', function (Blueprint $table) {
                $table->dropColumn('is_primary');
            });
        }
    }
};
