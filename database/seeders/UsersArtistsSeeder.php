<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use File;

class UsersArtistsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $json = File::get("database/seed-data/users_artists.json");
        $users_artists = json_decode($json);

        if (Schema::hasTable('users_artists')) {
            foreach ($users_artists as $key => $value) {
                DB::table('users_artists')->insert(
                    ['user_id' => $value->user_id, 'artist_id' => $value->artist_id]
                );
            }
        }
    }
}
