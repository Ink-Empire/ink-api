<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use File;

class TattoosStylesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $json = File::get("database/seed-data/tattoos_styles.json");
        $tattoos_styles = json_decode($json);

        if (Schema::hasTable('tattoos_styles')) {
            foreach ($tattoos_styles as $key => $value) {
                DB::table('tattoos_styles')->insert(
                    ['tattoo_id' => $value->tattoo_id, 'style_id' => $value->style_id]
                );
            }
        }
    }
}
