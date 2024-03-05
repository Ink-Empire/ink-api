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
            $count = 0;

            while ($count <= 50) {
                DB::table('users_tattoos')->insert(
                    ['user_id' => rand(1, 50), 'tattoo_id' => rand(1, 10)]
                );
                $count++;
            }
        }
    }
}
