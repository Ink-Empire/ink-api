<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use File;

class BusinessHoursSeeder extends Seeder
{
    /**
     * chatGPT prompt used to generate the seed data
     * ----------------------------------------------
     * I need a json object with the following fields: "studio_id", "day_id", "open_time", "close_time"
     * there are five studios, with IDs from 1 to 5. there are seven days, starting on Monday, with Monday having a day_id of "1" and Sunday an ID of "7".
     * open_time is a time between 07:00 and 13:00, and close_time is a time between 17:00 and 23:00.
     * can you generate this json for me such that each studio has open and close times for at least 5 days of the week?
     */

    /**
     * Run the database seeds.
     */
    public function run()
    {
        $json = File::get("database/seed-data/business_hours.json");
        $business_hours = json_decode($json);

        if (Schema::hasTable('business_hours')) {
            foreach ($business_hours as $key => $value) {
                DB::table('business_hours')->insert(
                    [
                        'studio_id' => $value->studio_id,
                        'day_id' => $value->day_id,
                        'open_time' => $value->open_time,
                        'close_time' => $value->close_time
                    ]
                );
            }
        }
    }
}
