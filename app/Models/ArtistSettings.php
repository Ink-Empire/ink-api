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
        'hourly_rate',
        'deposit_amount',
        'consultation_fee',
        'minimum_session',
        'seeking_guest_spots',
        'guest_spot_regions',
        'watermark_image_id',
        'watermark_opacity',
        'watermark_position',
        'watermark_enabled',
    ];

    protected $casts = [
        'settings' => 'array',
        'books_open' => 'boolean',
        'accepts_walk_ins' => 'boolean',
        'accepts_deposits' => 'boolean',
        'accepts_consultations' => 'boolean',
        'accepts_appointments' => 'boolean',
        'hourly_rate' => 'integer',
        'deposit_amount' => 'integer',
        'consultation_fee' => 'integer',
        'minimum_session' => 'integer',
        'seeking_guest_spots' => 'boolean',
        'guest_spot_regions' => 'array',
        'watermark_opacity' => 'integer',
        'watermark_enabled' => 'boolean',
    ];

    public function artist()
    {
        return $this->belongsTo(Artist::class);
    }

    public function watermarkImage()
    {
        return $this->belongsTo(Image::class, 'watermark_image_id');
    }
}

