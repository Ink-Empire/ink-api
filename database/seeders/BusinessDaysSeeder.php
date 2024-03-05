<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BusinessDaysSeeder extends Seeder
{
    const DAYS = [
        'Monday',
        'Tuesday',
        'Wednesday',
        'Thursday',
        'Friday',
        'Saturday',
        'Sunday'
    ];

    /**
     * Run the database seeds.
     */
    public function run()
    {
        if (Schema::hasTable('business_days')) {

            foreach (self::DAYS as $day) {
                DB::table('business_days')->insert(
                    [
                        'day' => $day
                    ]
                );
            }
        }
    }
}
