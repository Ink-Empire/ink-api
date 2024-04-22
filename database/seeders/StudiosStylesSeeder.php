<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use File;

class StudiosStylesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $json = File::get("database/seed-data/studios_styles.json");
        $studios_styles = json_decode($json);

        if (Schema::hasTable('studios_styles')) {
            foreach ($studios_styles as $key => $value) {
                DB::table('studios_styles')->insert(
                    ['studio_id' => $value->studio_id, 'style_id' => $value->style_id]
                );
            }
        }
    }
}
