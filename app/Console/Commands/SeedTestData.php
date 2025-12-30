<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

class SeedTestData extends Command
{
    protected $signature = 'data:seed
                           {--all : Seed everything (full database reset)}
                           {--users : Seed users and related data}
                           {--studios : Seed studios and related data}
                           {--tattoos : Seed tattoos and related data}
                           {--lookups : Seed lookup tables only (styles, tags, etc.)}
                           {--elastic : Rebuild Elasticsearch indexes after seeding}
                           {--fresh : Wipe database and run migrations first}
                           {--force : Skip confirmation prompts}';

    protected $description = 'Interactive seeder for test data - choose what to seed';

    /**
     * Seeder groups with their dependencies.
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
            'requires' => ['lookups', 'images', 'addresses'],
            'seeders' => [
                \Database\Seeders\StudioSeeder::class,
                \Database\Seeders\StudiosStylesSeeder::class,
                \Database\Seeders\BusinessHoursSeeder::class,
            ],
        ],
        'users' => [
            'label' => 'Users (+ styles, favorites, studio associations)',
            'requires' => ['lookups', 'images', 'studios'],
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
            'requires' => ['lookups', 'images', 'users'],
            'seeders' => [
                \Database\Seeders\TattooSeeder::class,
                \Database\Seeders\TattoosStylesSeeder::class,
                \Database\Seeders\TattoosTagsSeeder::class,
                \Database\Seeders\UsersTattoosSeeder::class,
            ],
        ],
        'appointments' => [
            'label' => 'Appointments & Conversations',
            'requires' => ['users'],
            'seeders' => [
                \Database\Seeders\AppointmentSeeder::class,
                \Database\Seeders\ConversationSeeder::class,
            ],
        ],
    ];

    public function handle(): int
    {
        $this->info('🌱 Interactive Test Data Seeder');
        $this->newLine();

        $selections = $this->getSelections();

        if (empty($selections)) {
            $this->warn('No seeders selected. Exiting.');
            return 0;
        }

        // Resolve dependencies
        $resolved = $this->resolveDependencies($selections);

        // Show what will be seeded
        $this->showPlan($resolved);

        if (!$this->option('force') && !$this->confirm('Proceed with seeding?')) {
            $this->info('Cancelled.');
            return 0;
        }

        // Handle fresh database if requested
        if ($this->option('fresh') || in_array('all', $selections)) {
            $this->handleFreshDatabase();
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
        foreach (['users', 'studios', 'tattoos', 'lookups'] as $opt) {
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
            'appointments' => '📅 Appointments & Conversations',
            'lookups' => '📚 Lookup tables only (styles, tags, etc.)',
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

            // Add dependencies first
            if (isset($group['requires'])) {
                foreach ($group['requires'] as $dep) {
                    if (!in_array($dep, $resolved)) {
                        array_unshift($toResolve, $dep);
                    }
                }
                // Re-add current after dependencies
                $toResolve[] = $current;
                continue;
            }

            $resolved[] = $current;
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
            Artisan::call('elastic:delete-index "App\\\\Models\\\\Tattoo"');
            Artisan::call('elastic:delete-index "App\\\\Models\\\\Artist"');
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
                try {
                    Artisan::call('db:seed', [
                        '--class' => $seederClass,
                        '--force' => true,
                    ]);
                } catch (\Exception $e) {
                    $this->warn("    ⚠ Failed: " . class_basename($seederClass) . " - " . $e->getMessage());
                }
            }

            $this->info("  ✓ {$label}");
        }
    }

    private function rebuildElasticsearch(array $resolved): void
    {
        $this->newLine();
        $this->info('🔍 Rebuilding Elasticsearch indexes...');

        $rebuildTattoos = in_array('tattoos', $resolved);
        $rebuildArtists = in_array('users', $resolved);

        try {
            if ($rebuildTattoos) {
                Artisan::call('elastic:create-index-ifnotexists "App\\Models\\Tattoo"');
                Artisan::call('scout:import "App\\Models\\Tattoo"');
                $this->line('  ✓ Tattoos indexed');
            }

            if ($rebuildArtists) {
                Artisan::call('elastic:create-index-ifnotexists "App\\Models\\Artist"');
                Artisan::call('scout:import "App\\Models\\Artist"');
                $this->line('  ✓ Artists indexed');
            }
        } catch (\Exception $e) {
            $this->warn('  ⚠ Elasticsearch indexing failed: ' . $e->getMessage());
        }
    }
}
