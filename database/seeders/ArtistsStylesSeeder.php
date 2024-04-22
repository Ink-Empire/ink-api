<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use File;

class ArtistsStylesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $json = File::get("database/seed-data/artists_styles.json");
        $artists_styles = json_decode($json);

        if (Schema::hasTable('artists_styles')) {
            foreach ($artists_styles as $key => $value) {
                DB::table('artists_styles')->insert(
                    ['artist_id' => $value->artist_id, 'style_id' => $value->style_id]
                );
            }
        }
    }
}
