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
            $count = 0;

            while ($count <= 50) {
                //\Log::info($count);
                DB::table('artists_styles')->insert(
                    ['artist_id' => rand(1, 50), 'style_id' => rand(1, 10)]
                );

                $count++;
            }
        }
    }
}
