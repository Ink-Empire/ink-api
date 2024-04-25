<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use File;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $json = File::get("database/seed-data/users.json");
        $users = json_decode($json);

        if (Schema::hasTable('users')) {
            foreach ($users as $key => $value) {
                User::create([
                    "name" => $value->name,
                    "about" => $value->about,
                    "location" => $value->location,
                    "location_lat_long" => $value->location_lat_long,
                    "email" => $value->email,
                    "password" => "$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi", // password
                    "phone" => $value->phone,
                    "studio_id" => $value->studio_id ?? null,
                    "type_id" => $value->type_id,
                    "image_id" => $value->image_id,
                    "remember_token" => Str::random(10),
                ]);
            }
        }
    }
}
