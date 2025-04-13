<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use File;

class UsernameSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $json = File::get("database/seed-data/usernames.json");
        $usernames = json_decode($json);

        if (Schema::hasTable('users')) {
            foreach ($usernames as $key => $value) {
                $user = User::find($value->id);
                if ($user) {
                    $user->update([
                        "username" => $value->username,
                        "slug" => $value->slug,
                    ]);
                }
            }
        }
    }
}