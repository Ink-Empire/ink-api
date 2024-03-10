<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TattoosStylesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        if (Schema::hasTable('tattoos_styles')) {
            for ($count = 1; $count < 51; $count++) {
                DB::table('tattoos_styles')->insert(
                    ['tattoo_id' => $count, 'style_id' => rand(1,10)]
                );
            }
        }
    }
}
