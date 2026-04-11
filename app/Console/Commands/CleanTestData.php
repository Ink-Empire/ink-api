<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Models\Studio;
use App\Models\Tattoo;
use App\Models\Appointment;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Image;
use App\Models\Address;
use App\Models\ProfileView;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

class CleanTestData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'data:clean
                           {--preserve-admin : Preserve admin users (is_admin = true)}
                           {--preserve-lookups : Preserve lookup tables (styles, tags, placements, etc.)}
                           {--post-types : Only remove PostTypeSeeder test records (flash/seeking)}
                           {--reseed : Re-run database seeders after cleanup}
                           {--force : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean test data from the database, preserving lookup tables and optionally admin users';

    /**
     * Tables that contain lookup/reference data and should be preserved by default.
     */
    private array $lookupTables = [
        'styles',
        'tags',
        'placements',
        'blocked_terms',
        'types',
        'business_days',
        'countries',
        'locations',
        'subjects',
    ];

    /**
     * Tables with user/studio data that should be cleaned (in order for cascade deletes).
     */
    private array $cleanableTables = [
        'conversations',
        'messages',
        'message_attachments',
        'appointments',
        'profile_views',
        'tattoos',
        'studios',
        'users',
        'addresses',
        'images',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $preserveAdmin = $this->option('preserve-admin');
        $preserveLookups = $this->option('preserve-lookups');
        $reseed = $this->option('reseed');
        $force = $this->option('force');

        $this->info('🧹 Test Data Cleanup Tool');
        $this->newLine();

        // Targeted post-type cleanup only
        if ($this->option('post-types')) {
            return $this->handlePostTypesOnly($force);
        }

        // Show what will be deleted
        $this->showDataSummary($preserveAdmin);

        if (!$force) {
            $this->warn('⚠️  This will permanently delete data from the database!');

            if ($preserveAdmin) {
                $this->info('   Admin users (is_admin = true) will be preserved.');
            }

            if (!$this->confirm('Do you want to proceed?')) {
                $this->info('Operation cancelled.');
                return 0;
            }
        }

        $this->newLine();
        $this->info('Starting cleanup...');

        try {
            DB::beginTransaction();

            $stats = $this->performCleanup($preserveAdmin, $preserveLookups);

            DB::commit();

            $this->displayResults($stats);

            if ($reseed) {
                $this->reseedDatabase();
            }

            $this->newLine();
            $this->info('✅ Cleanup completed successfully!');

            return 0;

        } catch (\Exception $e) {
            DB::rollBack();

            $this->error('❌ Cleanup failed: ' . $e->getMessage());
            $this->error('Database has been rolled back to previous state.');

            \Log::error('CleanTestData command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return 1;
        }
    }

    /**
     * Targeted cleanup of records created by PostTypeSeeder.
     */
    private function handlePostTypesOnly(bool $force): int
    {
        $marker = \Database\Seeders\PostTypeSeeder::MARKER;
        $this->info("Removing PostTypeSeeder test records (marker: {$marker})");
        $this->newLine();

        $count = Tattoo::where('description', 'like', '%'.$marker.'%')->count();
        if ($count === 0) {
            $this->info('No post-type test records found.');
            return 0;
        }

        $this->line("Found {$count} tattoo(s) to remove (plus linked leads).");

        if (!$force && !$this->confirm('Proceed with deletion?')) {
            $this->info('Cancelled.');
            return 0;
        }

        $stats = \Database\Seeders\PostTypeSeeder::cleanup();

        $this->info("✓ Deleted {$stats['tattoos']} tattoo(s) and {$stats['leads']} lead(s).");

        try {
            Artisan::call('scout:import', ['model' => Tattoo::class]);
            $this->line('✓ Tattoo index refreshed');
        } catch (\Exception $e) {
            $this->warn('Could not refresh tattoo index: ' . $e->getMessage());
        }

        return 0;
    }

    /**
     * Show a summary of data that will be deleted.
     */
    private function showDataSummary(bool $preserveAdmin): void
    {
        $this->info('📊 Current data summary:');
        $this->newLine();

        $data = [
            ['Users', User::count(), $preserveAdmin ? User::where('is_admin', false)->count() : User::count()],
            ['Studios', Studio::count(), Studio::count()],
            ['Tattoos', Tattoo::count(), Tattoo::count()],
            ['Appointments', Appointment::count(), Appointment::count()],
            ['Conversations', Conversation::count(), Conversation::count()],
            ['Messages', Message::count(), Message::count()],
            ['Images', Image::count(), Image::count()],
            ['Addresses', Address::count(), Address::count()],
            ['Profile Views', ProfileView::count(), ProfileView::count()],
        ];

        $this->table(
            ['Table', 'Total Records', 'To Delete'],
            $data
        );

        if ($preserveAdmin) {
            $adminCount = User::where('is_admin', true)->count();
            $this->info("🔒 {$adminCount} admin user(s) will be preserved.");
        }

        $this->newLine();
    }

    /**
     * Perform the actual cleanup.
     */
    private function performCleanup(bool $preserveAdmin, bool $preserveLookups): array
    {
        $stats = [];

        // Disable foreign key checks temporarily for faster deletion
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            // 1. Delete conversations and messages first (they reference appointments and users)
            $stats['conversations'] = Conversation::count();
            Conversation::query()->delete();
            $this->line('  ✓ Deleted conversations');

            $stats['messages'] = Message::count();
            Message::query()->delete();
            $this->line('  ✓ Deleted messages');

            // 2. Delete appointments
            $stats['appointments'] = Appointment::count();
            Appointment::query()->delete();
            $this->line('  ✓ Deleted appointments');

            // 3. Delete profile views
            $stats['profile_views'] = ProfileView::count();
            ProfileView::query()->delete();
            $this->line('  ✓ Deleted profile views');

            // 4. Delete tattoos (this will cascade to tattoos_styles, tattoos_tags, tattoos_images)
            $stats['tattoos'] = Tattoo::count();
            Tattoo::query()->delete();
            $this->line('  ✓ Deleted tattoos');

            // 5. Delete studios (this will cascade to studios_styles, artists_studios, business_hours)
            $stats['studios'] = Studio::count();
            Studio::query()->delete();
            $this->line('  ✓ Deleted studios');

            // 6. Delete users (optionally preserving admins)
            if ($preserveAdmin) {
                $stats['users'] = User::where('is_admin', false)->count();
                User::where('is_admin', false)->delete();
                $this->line('  ✓ Deleted non-admin users');
            } else {
                $stats['users'] = User::count();
                User::query()->delete();
                $this->line('  ✓ Deleted all users');
            }

            // 7. Clean up orphaned records
            $stats['addresses'] = Address::count();
            Address::query()->delete();
            $this->line('  ✓ Deleted addresses');

            $stats['images'] = Image::count();
            Image::query()->delete();
            $this->line('  ✓ Deleted images');

            // 8. Clean up pivot tables that might have orphaned records
            $this->cleanPivotTables();
            $this->line('  ✓ Cleaned pivot tables');

            // 9. Optionally clean lookup tables
            if (!$preserveLookups) {
                $this->cleanLookupTables($stats);
            }

        } finally {
            // Re-enable foreign key checks
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        return $stats;
    }

    /**
     * Clean up any orphaned pivot table records.
     */
    private function cleanPivotTables(): void
    {
        $pivotTables = [
            'users_tattoos',
            'users_studios',
            'artists_studios',
            'users_artists',
            'users_styles',
            'studios_styles',
            'tattoos_styles',
            'tattoos_tags',
            'tattoos_images',
            'artist_travel_regions',
            'artist_wishlists',
            'conversation_participants',
            'message_attachments',
        ];

        foreach ($pivotTables as $table) {
            try {
                DB::table($table)->delete();
            } catch (\Exception $e) {
                // Table might not exist, skip it
                \Log::warning("Could not clean pivot table: {$table}", ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Clean lookup tables if requested.
     */
    private function cleanLookupTables(array &$stats): void
    {
        $this->line('  Cleaning lookup tables...');

        foreach ($this->lookupTables as $table) {
            try {
                $count = DB::table($table)->count();
                DB::table($table)->delete();
                $stats[$table] = $count;
                $this->line("    ✓ Deleted {$count} records from {$table}");
            } catch (\Exception $e) {
                $this->warn("    ⚠ Could not clean {$table}: " . $e->getMessage());
            }
        }
    }

    /**
     * Re-run database seeders.
     */
    private function reseedDatabase(): void
    {
        $this->newLine();
        $this->info('🌱 Re-running database seeders...');
        $this->newLine();

        // Run essential seeders (not the full DatabaseSeeder which wipes everything)
        $seeders = [
            'StyleSeeder',
            'TagSeeder',
            'PlacementSeeder',
            'BlockedTermSeeder',
            'BusinessDaysSeeder',
            'LocationSeeder',
            'AdminSeeder',
        ];

        foreach ($seeders as $seeder) {
            try {
                Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]);
                $this->line("  ✓ Ran {$seeder}");
            } catch (\Exception $e) {
                $this->warn("  ⚠ Failed to run {$seeder}: " . $e->getMessage());
            }
        }

        $this->info('  Seeders completed.');
    }

    /**
     * Display the cleanup results.
     */
    private function displayResults(array $stats): void
    {
        $this->newLine();
        $this->info('📈 Cleanup Results:');
        $this->newLine();

        $tableData = [];
        foreach ($stats as $table => $count) {
            $tableData[] = [ucfirst(str_replace('_', ' ', $table)), $count];
        }

        $this->table(
            ['Table', 'Records Deleted'],
            $tableData
        );
    }
}
