<?php

namespace Database\Seeders;

use App\Models\Country;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use File;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
     {
         $json = File::get("database/seed-data/countries.json");
         $countries = json_decode($json);

         if (Schema::hasTable('countries')) {
            foreach ($countries as $key => $value) {
                Country::create([
                    "name" => $value->name,
                    "code" => $value->code,
                    "is_active" => $value->is_active,
                ]);
             }
         }
     }
}
