<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudioAvailability extends Model
{
    protected $table = 'studio_availability';

    protected $fillable = [
        'studio_id',
        'day_of_week',
        'start_time',
        'end_time',
        'is_day_off',
    ];

    public function studio()
    {
        return $this->belongsTo(Studio::class);
    }

    public function getDayNameAttribute()
    {
        return $this->getDayName($this->day_of_week);
    }

    private function getDayName($dayNumber)
    {
        $days = [
            0 => 'Sunday',
            1 => 'Monday',
            2 => 'Tuesday',
            3 => 'Wednesday',
            4 => 'Thursday',
            5 => 'Friday',
            6 => 'Saturday',
        ];

        return $days[$dayNumber] ?? null;
    }
}
