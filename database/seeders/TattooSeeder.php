<?php

namespace Database\Seeders;

use App\Models\Tattoo;
use Illuminate\Database\Seeder;

class TattooSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Tattoo::factory()
            ->count(50)
            ->create();
    }
}
