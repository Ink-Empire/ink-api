<?php

namespace Database\Seeders;

use App\Models\Image;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use File;

class ImageSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $json = File::get("database/seed-data/images.json");
        $images = json_decode($json);

        if (Schema::hasTable('images')) {
            foreach ($images as $key => $value) {
                Image::create([
                    "filename" => $value->filename,
                    "uri" => $value->uri,
                    "is_primary" => 0
                ]);
            }
        }
    }
}
