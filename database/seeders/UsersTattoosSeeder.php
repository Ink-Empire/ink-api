<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use File;

class UsersTattoosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        $json = File::get("database/seed-data/users_tattoos.json");
        $users_tattoos = json_decode($json);

        if (Schema::hasTable('users_tattoos')) {
            foreach ($users_tattoos as $key => $value) {
                DB::table('users_tattoos')->insert(
                    ['user_id' => $value->user_id, 'tattoo_id' => $value->tattoo_id]
                );
            }
        }
    }
}
