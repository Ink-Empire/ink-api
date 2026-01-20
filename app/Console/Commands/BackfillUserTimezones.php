<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BackfillUserTimezones extends Command
{
    protected $signature = 'users:backfill-timezones
                           {--count= : Limit the number of users to process}
                           {--force : Force update of users that already have a timezone}
                           {--dry-run : Show what would be updated without making changes}
                           {--user= : Only process a specific user ID}';

    protected $description = 'Backfill timezone field for users based on their location coordinates';

    protected ?string $apiKey;

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $this->apiKey = config('services.google.places_api_key');

        if (empty($this->apiKey)) {
            $this->error('Google API key not configured. Set GOOGLE_PLACES_API_KEY in your .env file.');
            return 1;
        }

        $count = $this->option('count') ? (int) $this->option('count') : null;
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');
        $userId = $this->option('user') ? (int) $this->option('user') : null;

        $this->info('Starting timezone backfill process...');
        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }
        $this->newLine();

        $query = $this->buildQuery($force, $userId);
        $totalAvailable = $query->count();

        if ($totalAvailable === 0) {
            $this->warn('No users found matching the criteria.');
            $this->info('Users need location_lat_long set and timezone empty (unless using --force).');
            return 0;
        }

        if ($count) {
            $query->limit($count);
            $this->info("Processing $count out of $totalAvailable available users...");
        } else {
            $this->info("Processing all $totalAvailable available users...");
        }

        $users = $query->get();

        if ($users->isEmpty()) {
            $this->warn('No users to process.');
            return 0;
        }

        if ($users->count() > 100 && !$this->confirm("You're about to process {$users->count()} users. This will make API calls to Google. Continue?")) {
            $this->info('Operation cancelled.');
            return 0;
        }

        $this->processBackfill($users, $dryRun);

        return 0;
    }

    private function buildQuery(bool $force, ?int $userId)
    {
        $query = User::whereNotNull('location_lat_long')
            ->where('location_lat_long', '!=', '');

        if ($userId) {
            $query->where('id', $userId);
        }

        if (!$force) {
            $query->where(function ($q) {
                $q->whereNull('timezone')
                    ->orWhere('timezone', '');
            });
        }

        $query->orderBy('id', 'asc');

        return $query;
    }

    private function processBackfill($users, bool $dryRun): void
    {
        $progressBar = $this->output->createProgressBar($users->count());
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% -- %message%');
        $progressBar->setMessage('Starting...');
        $progressBar->start();

        $stats = [
            'updated' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        $results = [];

        foreach ($users as $user) {
            $progressBar->setMessage("Processing user ID: {$user->id}");

            try {
                $latLong = $user->location_lat_long;

                if (empty($latLong) || !str_contains($latLong, ',')) {
                    $stats['skipped']++;
                    $progressBar->advance();
                    continue;
                }

                [$lat, $lng] = explode(',', $latLong);
                $lat = trim($lat);
                $lng = trim($lng);

                if (!is_numeric($lat) || !is_numeric($lng)) {
                    $stats['skipped']++;
                    $progressBar->advance();
                    continue;
                }

                $timezone = $this->lookupTimezone((float) $lat, (float) $lng);

                if ($timezone) {
                    $results[] = [
                        'user_id' => $user->id,
                        'name' => $user->name ?? $user->username,
                        'location' => $user->location,
                        'coords' => $latLong,
                        'timezone' => $timezone,
                    ];

                    if (!$dryRun) {
                        $user->update(['timezone' => $timezone]);
                    }

                    $stats['updated']++;

                    Log::info("Backfill: Set timezone for user", [
                        'user_id' => $user->id,
                        'timezone' => $timezone,
                        'location' => $user->location,
                    ]);
                } else {
                    $stats['failed']++;

                    Log::warning("Backfill: Failed to get timezone for user", [
                        'user_id' => $user->id,
                        'lat_long' => $latLong,
                    ]);
                }

                // Rate limit - Google allows 50 requests per second, but let's be conservative
                usleep(100000); // 0.1 seconds between requests

            } catch (\Exception $e) {
                $stats['failed']++;

                Log::error("Backfill: Error processing user", [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $progressBar->advance();
        }

        $progressBar->setMessage('Complete!');
        $progressBar->finish();
        $this->newLine(2);

        $this->displayResults($stats, $results, $dryRun);
    }

    private function lookupTimezone(float $lat, float $lng): ?string
    {
        try {
            $timestamp = time();

            $response = Http::get('https://maps.googleapis.com/maps/api/timezone/json', [
                'location' => "{$lat},{$lng}",
                'timestamp' => $timestamp,
                'key' => $this->apiKey,
            ]);

            if (!$response->successful()) {
                Log::error('Google Timezone API HTTP error', ['response' => $response->body()]);
                return null;
            }

            $data = $response->json();

            if ($data['status'] !== 'OK') {
                Log::error('Google Timezone API status error', [
                    'status' => $data['status'],
                    'error_message' => $data['errorMessage'] ?? null,
                ]);
                return null;
            }

            return $data['timeZoneId'] ?? null;

        } catch (\Exception $e) {
            Log::error('Google Timezone API exception', ['message' => $e->getMessage()]);
            return null;
        }
    }

    private function displayResults(array $stats, array $results, bool $dryRun): void
    {
        if ($dryRun) {
            $this->warn('DRY RUN - No changes were made');
            $this->newLine();
        }

        $this->info('Timezone backfill process completed!');
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                [$dryRun ? 'Would Update' : 'Updated', $stats['updated']],
                ['Failed', $stats['failed']],
                ['Skipped (invalid coords)', $stats['skipped']],
            ]
        );

        if (!empty($results) && count($results) <= 50) {
            $this->newLine();
            $this->info('Updated users:');
            $this->table(
                ['User ID', 'Name', 'Location', 'Timezone'],
                array_map(function ($r) {
                    return [$r['user_id'], $r['name'], $r['location'] ?? '-', $r['timezone']];
                }, $results)
            );
        }

        if ($stats['failed'] > 0) {
            $this->warn("{$stats['failed']} users failed to process. Check the logs for details.");
        }

        $this->newLine();
        $this->info('Tips:');
        $this->info('  --dry-run    Preview changes without updating the database');
        $this->info('  --force      Update users who already have a timezone set');
        $this->info('  --user=ID    Process only a specific user');
        $this->info('  --count=N    Limit to N users');
    }
}
