<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Studio hours lived in two tables. The dashboard writes studio_availability,
 * while business_hours was only reachable through a legacy endpoint nothing
 * called, so studios edited in the dashboard published no hours at all.
 *
 * Everything now reads studio_availability, so the rows still stranded in
 * business_hours are moved across. Studios that already have availability are
 * skipped: their newer data wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('business_hours')) {
            return;
        }

        $withAvailability = DB::table('studio_availability')->distinct()->pluck('studio_id')->all();

        $legacy = DB::table('business_hours')
            ->join('business_days', 'business_days.id', '=', 'business_hours.day_id')
            ->whereNotIn('business_hours.studio_id', $withAvailability)
            ->select(
                'business_hours.studio_id',
                'business_hours.open_time',
                'business_hours.close_time',
                'business_days.day'
            )
            ->get();

        // business_days is Monday-first; day_of_week is Sunday-first.
        $dayOfWeek = [
            'Sunday' => 0, 'Monday' => 1, 'Tuesday' => 2, 'Wednesday' => 3,
            'Thursday' => 4, 'Friday' => 5, 'Saturday' => 6,
        ];

        foreach ($legacy as $row) {
            if (! isset($dayOfWeek[$row->day])) {
                continue;
            }

            DB::table('studio_availability')->updateOrInsert(
                [
                    'studio_id' => $row->studio_id,
                    'day_of_week' => $dayOfWeek[$row->day],
                ],
                [
                    'start_time' => $row->open_time,
                    'end_time' => $row->close_time,
                    'is_day_off' => false,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        // The legacy rows are left in place by up(), so there is nothing to undo.
    }
};
