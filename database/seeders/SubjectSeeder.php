<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SubjectSeeder extends Seeder
{
    const subject_array = [
        'Animal',
        'Nature',
        'Alien',
        'Skull',
        'Woman',
        'Man',
        'Gypsy',
        'Anchor',
        'Script',
        'Tree',
    ];

    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        if (Schema::hasTable('subjects')) {
            foreach (self::subject_array as $value) {
                DB::table('subjects')->insert(
                    ['name' => $value]
                );
            }
        }
    }
}
