<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class CalendarConnection extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_account_id',
        'provider_email',
        'calendar_id',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'sync_token',
        'last_synced_at',
        'sync_enabled',
        'requires_reauth',
        'webhook_channel_id',
        'webhook_resource_id',
        'webhook_expires_at',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'webhook_expires_at' => 'datetime',
        'sync_enabled' => 'boolean',
        'requires_reauth' => 'boolean',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    // Encrypt tokens at rest
    public function setAccessTokenAttribute($value): void
    {
        $this->attributes['access_token'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getAccessTokenAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function setRefreshTokenAttribute($value): void
    {
        $this->attributes['refresh_token'] = $value ? Crypt::encryptString($value) : null;
    }

    public function getRefreshTokenAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function externalEvents(): HasMany
    {
        return $this->hasMany(ExternalCalendarEvent::class);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at->isPast();
    }

    public function needsTokenRefresh(): bool
    {
        // Refresh if expiring within 5 minutes
        return $this->token_expires_at->subMinutes(5)->isPast();
    }
}
