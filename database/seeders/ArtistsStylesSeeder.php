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

        // Insert into users_styles (artists_styles table has been consolidated)
        foreach ($artists_styles as $key => $value) {
            DB::table('users_styles')->insertOrIgnore(
                ['user_id' => $value->artist_id, 'style_id' => $value->style_id]
            );
        }
    }
}
