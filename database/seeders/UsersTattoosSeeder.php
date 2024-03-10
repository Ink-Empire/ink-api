<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UsersTattoosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run()
    {
        if (Schema::hasTable('users_tattoos')) {
            for ($count = 1; $count < 51; $count++) {
                DB::table('users_tattoos')->insert(
                    ['user_id' => $count, 'tattoo_id' => rand(1, 10)]
                );
            }
        }
    }
}
