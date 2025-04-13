<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use File;

class ArtistAvailabilitySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $json = File::get("database/seed-data/working_hours.json");
        $workingHours = json_decode($json);

        if (Schema::hasTable('artist_availability')) {
            foreach ($workingHours as $hour) {
                DB::table('artist_availability')->insert([
                    'artist_id' => $hour->artist_id,
                    'day_of_week' => $hour->day_of_week,
                    'start_time' => $hour->start_time,
                    'end_time' => $hour->end_time,
                    'is_day_off' => $hour->is_day_off,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}