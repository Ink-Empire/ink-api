<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TattooLead extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'timing',
        'interested_by',
        'allow_artist_contact',
        'style_ids',
        'tag_ids',
        'custom_themes',
        'description',
        'is_active',
    ];

    protected $casts = [
        'style_ids' => 'array',
        'tag_ids' => 'array',
        'custom_themes' => 'array',
        'allow_artist_contact' => 'boolean',
        'is_active' => 'boolean',
        'interested_by' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Calculate the interested_by date based on timing.
     */
    public static function calculateInterestedBy(?string $timing): ?\Carbon\Carbon
    {
        if (!$timing) {
            return null;
        }

        return match ($timing) {
            'week' => now()->addWeek(),
            'month' => now()->addMonth(),
            'year' => now()->addYear(),
            default => null,
        };
    }

    /**
     * Scope for active leads.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for leads allowing artist contact.
     */
    public function scopeContactable($query)
    {
        return $query->where('allow_artist_contact', true);
    }
}
