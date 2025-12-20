<?php

namespace Database\Seeders;

use App\Models\Tag;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use File;

class TagSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $json = File::get("database/seed-data/tags.json");
        $tags = json_decode($json);

        if (Schema::hasTable('tags')) {
            foreach ($tags as $key => $value) {
                // Use firstOrCreate to avoid duplicates
                Tag::firstOrCreate(
                    ['slug' => Str::slug($value->name)],
                    ['name' => strtolower($value->name)]
                );
            }
        }
    }
}
