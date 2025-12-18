<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Artisan;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
//        Artisan::call("db:wipe");
//        Artisan::call("migrate");

        Artisan::call('elastic:delete-index "App\\\\Models\\\\Tattoo"');
        Artisan::call('elastic:delete-index "App\\\\Models\\\\Artist"');

        $this->call([
            AddressSeeder::class,
            StyleSeeder::class,
            ImageSeeder::class,
            StudioSeeder::class,
            UserSeeder::class,
            SubjectSeeder::class,
            TattooSeeder::class,
            TattoosStylesSeeder::class,
            UsersStylesSeeder::class,
            UsersArtistsSeeder::class,
            ArtistsStylesSeeder::class,
            StudiosStylesSeeder::class,
            UsersTattoosSeeder::class,
            UsersStudiosSeeder::class,
            BusinessDaysSeeder::class,
            BusinessHoursSeeder::class,
            UsernameSeeder::class,
            AppointmentSeeder::class,
            ArtistAvailabilitySeeder::class,
            ProfileViewSeeder::class,
            ConversationSeeder::class
        ]);

        try {
            Artisan::call('elastic:create-index-ifnotexists "App\\\Models\\\Tattoo"');

            Artisan::call('scout:import "App\\\Models\\\Tattoo"');

            Artisan::call('elastic:create-index-ifnotexists "App\\\\Models\\\\Artist"');

            Artisan::call('scout:import "App\\\Models\\\Artist"');

        } catch (\Exception $e) {
            \Log::error("Unable to create and populate elastic ", [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    }
}
