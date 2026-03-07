<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class StudioInvitation extends Model
{
    protected $fillable = [
        'studio_id',
        'invited_by_user_id',
        'email',
        'token',
        'claimed_at',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (StudioInvitation $invitation) {
            if (empty($invitation->token)) {
                $invitation->token = Str::random(64);
            }
        });
    }

    public function studio()
    {
        return $this->belongsTo(Studio::class);
    }

    public function invitedBy()
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
