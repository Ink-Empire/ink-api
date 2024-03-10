<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UsersArtistsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        if (Schema::hasTable('users_artists')) {
            for ($count = 1; $count < 51; $count++) {
                DB::table('users_artists')->insert(
                    ['user_id' => $count, 'artist_id' => rand(1, 50)]
                );
            }
        }
    }
}
