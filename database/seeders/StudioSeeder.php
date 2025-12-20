<?php

namespace Database\Seeders;

use App\Models\Studio;
use Illuminate\Database\Seeder;
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
                    "phone" => $value->phone,
                    "about" => $value->about,
                    "location" => $value->location,
                    "location_lat_long" => $value->location_lat_long,
                    "address_id" => $value->address_id ?? null,
                    "owner_id" => $value->owner_id ?? null,
                    "seeking_guest_artists" => $value->seeking_guest_artists ?? false,
                    "guest_spot_details" => $value->guest_spot_details ?? null,
                ]);
            }
        }
    }
}
