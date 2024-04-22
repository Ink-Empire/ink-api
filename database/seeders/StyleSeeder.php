<?php

namespace Database\Seeders;

use App\Models\Style;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use File;

class StyleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $json = File::get("database/seed-data/styles.json");
        $styles = json_decode($json);

        if (Schema::hasTable('styles')) {
            foreach ($styles as $key => $value) {
                Style::create([
                    "name" => $value->name
                ]);
            }
        }
    }
}
