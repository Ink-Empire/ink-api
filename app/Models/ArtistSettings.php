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
        'books_open' => 'boolean',
        'accepts_walk_ins' => 'boolean',
        'accepts_deposits' => 'boolean',
        'accepts_consultations' => 'boolean',
        'accepts_appointments' => 'boolean',
    ];

    public function artist()
    {
        return $this->belongsTo(Artist::class);
    }
}

