<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UsersStylesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $json = File::get("database/seed-data/users_styles.json");
        $users_styles = json_decode($json);

        if (Schema::hasTable('users_styles')) {
            foreach ($users_styles as $key => $value) {
                DB::table('users_styles')->insert(
                    ['user_id' => $value->user_id, 'style_id' => $value->style_id]
                );
            }
        }
    }
}
