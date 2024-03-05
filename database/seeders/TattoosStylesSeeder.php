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
            $count = 0;

            while($count <= 50) {
                DB::table('tattoos_styles')->insert(
                    ['tattoo_id' => rand(1,50), 'style_id' => rand(1,10)]
                );
                $count++;
            }
        }
    }
}
