<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ArtistWishlist extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'artist_id',
        'notify_booking_open',
        'notified_at',
    ];

    protected $casts = [
        'notify_booking_open' => 'boolean',
        'notified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function artist()
    {
        return $this->belongsTo(User::class, 'artist_id');
    }
}
