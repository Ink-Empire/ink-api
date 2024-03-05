<?php

namespace Database\Seeders;

use App\Models\Style;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class StyleSeeder extends Seeder
{
    const style_array = [
        'Traditional',
        'Neotraditional',
        'Black and Gray',
        'Portrait',
        'Color Realism',
        'Japanese',
        'New School',
        'Trash Polka',
        'Watercolor',
        'Geometric',
    ];

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        if (Schema::hasTable('styles')) {
            foreach (self::style_array as $value) {
                DB::table('styles')->insert(
                    ['name' => $value]
                );
            }
        }
    }
}
