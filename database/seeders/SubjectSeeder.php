<?php

namespace Database\Seeders;

use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use File;

class SubjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $json = File::get("database/seed-data/subjects.json");
        $subjects = json_decode($json);

        foreach ($subjects as $key => $value) {
            Style::create([
                "name" => $value->name
            ]);
        }
    }
}
