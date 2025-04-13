<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $fillable = [
        'id',
        'title',
        'description',
        'client_id',
        'artist_id',
        'studio_id',
        'tattoo_id',
        'date',
        'status', // booked, completed, cancelled
        'type', // tattoo or consultation
        'all_day',
        'start_time',
        'end_time',
    ];

    public function client()
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function artist()
    {
        return $this->belongsTo(User::class, 'artist_id');
    }

    public function studio()
    {
        return $this->belongsTo(Studio::class);
    }

    public function tattoo()
    {
        return $this->belongsTo(Tattoo::class);
    }
}
