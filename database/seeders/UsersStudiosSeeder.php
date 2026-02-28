<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use File;

class UsersStudiosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $json = File::get("database/seed-data/users_studios.json");
        $users_studios = json_decode($json);

        if (Schema::hasTable('artists_studios')) {
            foreach ($users_studios as $key => $value) {
                DB::table('artists_studios')->insert(
                    ['user_id' => $value->user_id, 'studio_id' => $value->studio_id]
                );
            }
        }
    }
}
