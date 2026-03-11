<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ArtistInvitation extends Model
{
    protected $fillable = [
        'tattoo_id',
        'invited_by_user_id',
        'artist_name',
        'studio_name',
        'location',
        'location_lat_long',
        'email',
        'phone',
        'token',
        'claimed_by_user_id',
        'claimed_at',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (ArtistInvitation $invitation) {
            if (empty($invitation->token)) {
                $invitation->token = Str::random(64);
            }
        });
    }

    public function tattoo()
    {
        return $this->belongsTo(Tattoo::class);
    }

    public function invitedBy()
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    public function claimedBy()
    {
        return $this->belongsTo(User::class, 'claimed_by_user_id');
    }
}
