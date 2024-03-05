<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * @return void
     */
    public function run()
    {
        $this->call([
            AddressSeeder::class,
            StyleSeeder::class,
            ImageSeeder::class,
            StudioSeeder::class,
            UserSeeder::class,
            ArtistSeeder::class,
            SubjectSeeder::class,
            TattooSeeder::class,
            TattoosStylesSeeder::class,
            UsersStylesSeeder::class,
            ArtistsStylesSeeder::class,
            StudiosStylesSeeder::class,
            UsersTattoosSeeder::class,
            UsersStudiosSeeder::class,
            BusinessDaysSeeder::class,
            BusinessHoursSeeder::class
        ]);
    }
}
