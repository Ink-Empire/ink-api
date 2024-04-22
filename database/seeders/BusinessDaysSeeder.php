<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use File;

class BusinessDaysSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $json = File::get("database/seed-data/business_days.json");
        $business_days = json_decode($json);

        if (Schema::hasTable('business_days')) {
            foreach ($business_days as $key => $value) {
                DB::table('business_days')->insert(
                    ['day' => $value->day]
                );
            }
        }
    }
}
