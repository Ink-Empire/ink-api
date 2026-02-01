<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SocialMediaLink extends Model
{
    protected $fillable = [
        'user_id',
        'platform',
        'username',
    ];

    public const PLATFORMS = [
        'instagram',
        'facebook',
        'bluesky',
        'x',
        'tiktok',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getUrlAttribute(): string
    {
        return match ($this->platform) {
            'instagram' => "https://instagram.com/{$this->username}",
            'facebook' => "https://facebook.com/{$this->username}",
            'bluesky' => "https://bsky.app/profile/{$this->username}",
            'x' => "https://x.com/{$this->username}",
            'tiktok' => "https://tiktok.com/@{$this->username}",
            default => '',
        };
    }
}
