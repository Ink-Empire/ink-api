<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExternalCalendarEvent extends Model
{
    protected $fillable = [
        'calendar_connection_id',
        'appointment_id',
        'vendor_event_id',
        'title',
        'starts_at',
        'ends_at',
        'all_day',
        'status',
        'source',
        'metadata',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'all_day' => 'boolean',
        'metadata' => 'array',
    ];

    public function calendarConnection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    public function isFromGoogle(): bool
    {
        return $this->source === 'google';
    }

    public function isFromInkedIn(): bool
    {
        return $this->source === 'inkedin';
    }
}
