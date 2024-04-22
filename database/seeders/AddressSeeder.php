<?php

namespace Database\Seeders;

use App\Models\Address;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use File;

class AddressSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
     {
         $json = File::get("database/seed-data/addresses.json");
         $addresses = json_decode($json);

         if (Schema::hasTable('addresses')) {
            foreach ($addresses as $key => $value) {
                Address::create([
                    "first_name" => $value->first_name,
                    "last_name" => $value->last_name,
                    "address1" => $value->address1,
                    "address2" => $value->address2,
                    "city" => $value->city,
                    "state" => $value->state,
                    "postal_code" => $value->postal_code,
                    "country_code" => "US",
                    "phone" => $value->phone,
                    "is_active" => 1
                ]);
             }
         }
     }
}
