<?php

namespace Database\Seeders;

use App\Models\ProfileView;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use File;

class ProfileViewSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $json = File::get("database/seed-data/profile_views.json");
        $views = json_decode($json);

        if (Schema::hasTable('profile_views')) {
            foreach ($views as $key => $value) {
                $daysAgo = $value->days_ago ?? 0;
                $createdAt = Carbon::now()->subDays($daysAgo)->subHours(rand(0, 23))->subMinutes(rand(0, 59));

                ProfileView::create([
                    "viewer_id" => $value->viewer_id ?? null,
                    "viewable_type" => $value->viewable_type,
                    "viewable_id" => $value->viewable_id,
                    "ip_address" => $value->ip_address ?? null,
                    "user_agent" => $value->user_agent ?? null,
                    "referrer" => $value->referrer ?? null,
                    "created_at" => $createdAt,
                    "updated_at" => $createdAt,
                ]);
            }
        }
    }
}
