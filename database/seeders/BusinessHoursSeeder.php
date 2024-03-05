<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BusinessHoursSeeder extends Seeder
{
    const OPEN_TIME = [
        '07:00',
        '08:00',
        '09:00',
        '10:00',
        '11:00',
        '12:00',
        '13:00'
    ];

    const CLOSE_TIME = [
        '17:00',
        '18:00',
        '19:00',
        '20:00',
        '21:00',
        '22:00',
        '23:00'
    ];

    /**
     * Run the database seeds.
     */
    public function run()
    {
        if (Schema::hasTable('business_hours')) {

            for ($x = 1; $x <= 50; $x++) {
                for ($y = 1; $y <= 7; $y++) {
                    DB::table('business_hours')->insert(
                        [
                            'studio_id' => $x,
                            'day_id' => $y,
                            'open_time' => self::OPEN_TIME[array_rand(self::OPEN_TIME)],
                            'close_time' => self::CLOSE_TIME[array_rand(self::CLOSE_TIME)]
                        ]
                    );
                }
            }
        }
    }
}
