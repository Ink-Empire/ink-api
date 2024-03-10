<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ArtistsStylesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        if (Schema::hasTable('artists_styles')) {
            for ($count = 1; $count < 51; $count++) {
                DB::table('artists_styles')->insert(
                    ['artist_id' => $count, 'style_id' => rand(1, 10)]
                );
            }
        }
    }
}
