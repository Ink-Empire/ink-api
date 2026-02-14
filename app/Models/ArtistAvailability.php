<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArtistAvailability extends Model
{
    protected $table = 'artist_availability';

    protected $fillable = [
        'artist_id',
        'day_of_week',
        'start_time',
        'end_time',
        'consultation_start_time',
        'consultation_end_time',
        'is_day_off',
    ];

    public function artist()
    {
        return $this->belongsTo(User::class, 'artist_id');
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
