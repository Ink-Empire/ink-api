<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArtistSettings extends Model
{
    protected $table = 'artist_settings';

    protected $fillable = [
        'artist_id',
        'books_open',
        'accepts_walk_ins',
        'accepts_deposits',
        'accepts_consultations',
        'accepts_appointments',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    public function artist()
    {
        return $this->belongsTo(Artist::class);
    }
}

