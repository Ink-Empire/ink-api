<?php

namespace App\Console\Commands;

use App\Models\Artist;
use App\Models\Tattoo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SeedTestData extends Command
{
    protected $signature = 'data:seed
                           {--all : Seed everything (full database reset)}
                           {--users : Seed users and related data}
                           {--studios : Seed studios and related data}
                           {--tattoos : Seed tattoos and related data}
                           {--images : Seed images}
                           {--lookups : Seed lookup tables only (styles, tags, etc.)}
                           {--post-types : Seed flash + seeking test posts (non-destructive; safe to re-run)}
                           {--count= : Number of post-type records to create (default 20)}
                           {--elastic : Rebuild Elasticsearch indexes after seeding}
                           {--fresh : Wipe database and run migrations first}
                           {--clean : Run data:clean first to remove existing test data}
                           {--force : Skip confirmation prompts}';

    protected $description = 'Interactive seeder for test data - choose what to seed';

    /**
     * Seeder groups - no dependencies, each group is independent.
     * Assumes lookup tables (styles, tags, etc.) already exist.
     */
    private array $seederGroups = [
        'lookups' => [
            'label' => 'Lookup Tables (styles, tags, placements, etc.)',
            'seeders' => [
                \Database\Seeders\StyleSeeder::class,
                \Database\Seeders\TagSeeder::class,
                \Database\Seeders\SubjectSeeder::class,
                \Database\Seeders\LocationSeeder::class,
                \Database\Seeders\BusinessDaysSeeder::class,
                \Database\Seeders\PlacementSeeder::class,
                \Database\Seeders\BlockedTermSeeder::class,
            ],
        ],
        'images' => [
            'label' => 'Images',
            'seeders' => [
                \Database\Seeders\ImageSeeder::class,
            ],
        ],
        'addresses' => [
            'label' => 'Addresses',
            'seeders' => [
                \Database\Seeders\AddressSeeder::class,
            ],
        ],
        'studios' => [
            'label' => 'Studios (+ business hours, styles)',
            'seeders' => [
                \Database\Seeders\StudioSeeder::class,
                \Database\Seeders\StudiosStylesSeeder::class,
                \Database\Seeders\BusinessHoursSeeder::class,
            ],
        ],
        'users' => [
            'label' => 'Users (+ styles, favorites, availability)',
            'seeders' => [
                \Database\Seeders\UserSeeder::class,
                \Database\Seeders\UsernameSeeder::class,
                \Database\Seeders\UsersStylesSeeder::class,
                \Database\Seeders\ArtistsStylesSeeder::class,
                \Database\Seeders\UsersArtistsSeeder::class,
                \Database\Seeders\UsersStudiosSeeder::class,
                \Database\Seeders\ArtistAvailabilitySeeder::class,
                \Database\Seeders\ProfileViewSeeder::class,
            ],
        ],
        'tattoos' => [
            'label' => 'Tattoos (+ styles, tags, user favorites)',
            'seeders' => [
                \Database\Seeders\TattooSeeder::class,
                \Database\Seeders\TattoosStylesSeeder::class,
                \Database\Seeders\TattoosTagsSeeder::class,
                \Database\Seeders\UsersTattoosSeeder::class,
            ],
        ],
        'appointments' => [
            'label' => 'Appointments & Conversations',
            'seeders' => [
                \Database\Seeders\AppointmentSeeder::class,
                \Database\Seeders\ConversationSeeder::class,
            ],
        ],
        'post-types' => [
            'label' => 'Post Types Test Data (flash + seeking)',
            'seeders' => [
                \Database\Seeders\PostTypeSeeder::class,
            ],
        ],
    ];

    public function handle(): int
    {
        $this->output->writeln('🌱 Interactive Test Data Seeder');
        $this->output->writeln('');

        // Check if any explicit options were passed
        $hasExplicitOptions = $this->option('all') || $this->option('users') ||
                              $this->option('studios') || $this->option('tattoos') ||
                              $this->option('images') || $this->option('lookups') ||
                              $this->option('post-types');

        // Allow overriding record count for post-types via --count (read by seeder via env)
        if ($this->option('count')) {
            putenv('POST_TYPE_SEED_COUNT='.(int) $this->option('count'));
        }

        $selections = $this->getSelections();

        if (empty($selections)) {
            $this->warn('No seeders selected. Exiting.');
            return 0;
        }

        // Resolve dependencies
        $resolved = $this->resolveDependencies($selections);

        // Show what will be seeded
        $this->showPlan($resolved);

        // Skip confirmation if explicit options passed or --force
        if (!$this->option('force') && !$hasExplicitOptions && !$this->confirm('Proceed with seeding?')) {
            $this->info('Cancelled.');
            return 0;
        }

        // Handle fresh database if requested
        if ($this->option('fresh') || in_array('all', $selections)) {
            $this->handleFreshDatabase();
        }

        // Run data:clean first if requested
        if ($this->option('clean')) {
            $this->output->writeln('🧹 Cleaning existing test data...');
            Artisan::call('data:clean', [
                '--preserve-lookups' => true,
                '--force' => true,
            ]);
            $this->output->writeln('✓ Data cleaned');
        }

        // Run the seeders
        $this->runSeeders($resolved);

        // Handle Elasticsearch if requested
        if ($this->option('elastic') || in_array('all', $selections)) {
            $this->rebuildElasticsearch($resolved);
        }

        $this->newLine();
        $this->info('✅ Seeding completed!');

        return 0;
    }

    private function getSelections(): array
    {
        // Check for command-line options first
        if ($this->option('all')) {
            return ['all'];
        }

        $explicit = [];
        foreach (['users', 'studios', 'tattoos', 'images', 'lookups', 'post-types'] as $opt) {
            if ($this->option($opt)) {
                $explicit[] = $opt;
            }
        }

        if (!empty($explicit)) {
            return $explicit;
        }

        // Interactive mode
        $choices = [
            'all' => '🔄 Everything (full reset - wipes DB first)',
            'users' => '👤 Users (+ styles, favorites, availability)',
            'studios' => '🏢 Studios (+ business hours, styles)',
            'tattoos' => '🎨 Tattoos (+ styles, tags)',
            'images' => '🖼️  Images',
            'appointments' => '📅 Appointments & Conversations',
            'lookups' => '📚 Lookup tables only (styles, tags, etc.)',
            'post-types' => '⚡ Post Types Test Data (flash + seeking)',
        ];

        $this->info('What would you like to seed?');
        $this->newLine();

        foreach ($choices as $key => $label) {
            $this->line("  [{$key}] {$label}");
        }
        $this->newLine();

        $input = $this->ask('Enter choices (comma-separated, e.g., "users,tattoos")', 'all');

        return array_map('trim', explode(',', $input));
    }

    private function resolveDependencies(array $selections): array
    {
        if (in_array('all', $selections)) {
            return array_keys($this->seederGroups);
        }

        $resolved = [];
        $toResolve = $selections;

        while (!empty($toResolve)) {
            $current = array_shift($toResolve);

            if (in_array($current, $resolved)) {
                continue;
            }

            if (!isset($this->seederGroups[$current])) {
                $this->warn("Unknown seeder group: {$current}");
                continue;
            }

            $group = $this->seederGroups[$current];

            // Check if all dependencies are resolved
            $missingDeps = [];
            if (isset($group['requires'])) {
                foreach ($group['requires'] as $dep) {
                    if (!in_array($dep, $resolved)) {
                        $missingDeps[] = $dep;
                    }
                }
            }

            if (!empty($missingDeps)) {
                // Add missing dependencies to front, current to end
                foreach (array_reverse($missingDeps) as $dep) {
                    array_unshift($toResolve, $dep);
                }
                $toResolve[] = $current;
            } else {
                // All deps satisfied, add to resolved
                $resolved[] = $current;
            }
        }

        return $resolved;
    }

    private function showPlan(array $resolved): void
    {
        $this->info('📋 Seeding plan:');
        $this->newLine();

        foreach ($resolved as $group) {
            $label = $this->seederGroups[$group]['label'] ?? $group;
            $this->line("  ✓ {$label}");
        }

        $this->newLine();

        if ($this->option('fresh')) {
            $this->warn('⚠️  Database will be wiped and migrations re-run!');
        }
    }

    private function handleFreshDatabase(): void
    {
        $this->info('🗑️  Wiping database...');
        Artisan::call('db:wipe', ['--force' => true]);

        $this->info('📦 Running migrations...');
        Artisan::call('migrate', ['--force' => true]);

        $this->info('🔍 Clearing Elasticsearch indexes...');
        try {
            Artisan::call('elastic:delete-index', ['model' => Tattoo::class]);
            Artisan::call('elastic:delete-index', ['model' => Artist::class]);
        } catch (\Exception $e) {
            $this->warn('Could not clear Elasticsearch indexes: ' . $e->getMessage());
        }
    }

    private function runSeeders(array $resolved): void
    {
        $this->newLine();
        $this->info('🌱 Running seeders...');
        $this->newLine();

        foreach ($resolved as $group) {
            $groupConfig = $this->seederGroups[$group];
            $label = $groupConfig['label'] ?? $group;

            $this->line("  Seeding: {$label}");

            foreach ($groupConfig['seeders'] as $seederClass) {
                $seederName = class_basename($seederClass);
                try {
                    $seeder = app($seederClass);
                    $seeder->setContainer(app());
                    $seeder->setCommand($this);
                    $seeder->run();
                    $this->line("    ✓ {$seederName}");
                } catch (\Exception $e) {
                    $this->warn("    ⚠ Failed: {$seederName} - " . $e->getMessage());
                }
            }

            $this->info("  ✓ {$label}");
        }
    }

    private function rebuildElasticsearch(array $resolved): void
    {
        $this->newLine();
        $this->info('🔍 Rebuilding Elasticsearch indexes...');

        $rebuildTattoos = in_array('tattoos', $resolved) || in_array('post-types', $resolved);
        $rebuildArtists = in_array('users', $resolved);

        try {
            if ($rebuildTattoos) {
                Artisan::call('elastic:create-index-ifnotexists', ['model' => Tattoo::class]);
                Artisan::call('scout:import', ['model' => Tattoo::class]);
                $this->line('  ✓ Tattoos indexed');
            }

            if ($rebuildArtists) {
                Artisan::call('elastic:create-index-ifnotexists', ['model' => Artist::class]);
                Artisan::call('scout:import', ['model' => Artist::class]);
                $this->line('  ✓ Artists indexed');
            }
        } catch (\Exception $e) {
            $this->warn('  ⚠ Elasticsearch indexing failed: ' . $e->getMessage());
        }
    }
}
