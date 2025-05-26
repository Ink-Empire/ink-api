<?php

namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected static function booted()
    {
        static::saved(function ($appointment) {
            // Your logic here, e.g.:
            //TODO if the appointment status is changed to booked, send a notification to the client
        });
    }

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

    public function scopeForArtistWithStatus($query, $artistId, $status)
    {
        return $query->where('artist_id', $artistId)
            ->where('status', $status)
            ->with(['client', 'artist']);
    }
}
