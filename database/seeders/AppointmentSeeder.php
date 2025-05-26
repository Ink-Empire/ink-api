<?php

namespace Database\Seeders;

use App\Models\Appointment;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use File;

class AppointmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $json = File::get("database/seed-data/appointments.json");
        $appointments = json_decode($json);

        if (Schema::hasTable('appointments')) {
            foreach ($appointments as $key => $value) {
                Appointment::create([
                    "id" => $value->id,
                    "title" => $value->title,
                    "description" => $value->description,
                    "client_id" => $value->client_id,
                    "artist_id" => $value->artist_id,
                    "studio_id" => $value->studio_id,
                    "tattoo_id" => $value->tattoo_id,
                    "date" => $value->date,
                    "status" => $value->status,
                    "type" => $value->type,
                    "all_day" => $value->all_day,
                    "start_time" => $value->start_time,
                    "end_time" => $value->end_time,
                ]);
            }
        }
    }
}
