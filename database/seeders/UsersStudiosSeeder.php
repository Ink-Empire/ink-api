<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UsersStudiosSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        if (Schema::hasTable('users_studios')) {
            for ($count = 1; $count < 51; $count++) {
                DB::table('users_studios')->insert(
                    ['user_id' => $count, 'studio_id' => rand(1,10)]
                );
            }
        }
    }
}
