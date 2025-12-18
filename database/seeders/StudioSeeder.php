<?php

namespace Database\Seeders;

use App\Models\Studio;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use File;

class StudioSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $json = File::get("database/seed-data/studios.json");
        $studios = json_decode($json);

        if (Schema::hasTable('studios')) {
            foreach ($studios as $key => $value) {
                Studio::create([
                    "name" => $value->name,
                    "email" => $value->email,
                    'slug' => $value->slug,
                    "password" => "$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi", // password
                    "phone" => $value->phone,
                    "about" => $value->about,
                    "location" => $value->location,
                    "location_lat_long" => $value->location_lat_long,
                    "address_id" => $value->address_id
                ]);
            }
        }
    }
}
