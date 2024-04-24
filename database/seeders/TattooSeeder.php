<?php

namespace Database\Seeders;

use App\Models\Tattoo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use File;

class TattooSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $json = File::get("database/seed-data/tattoos.json");
        $tattoos = json_decode($json);

        if (Schema::hasTable('tattoos')) {
            foreach ($tattoos as $key => $value) {
                Tattoo::create([
                    "title" => $value->title,
                    "description" => $value->description,
                    "placement" => $value->placement,
                    "artist_id" => $value->artist_id,
                    "studio_id" => $value->studio_id,
                    "primary_style_id" => $value->primary_style_id,
                    "primary_subject_id" => $value->primary_subject_id,
                    "primary_image_id" => $value->primary_image_id
                ]);
            }
        }
    }
}
