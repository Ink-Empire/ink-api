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
        'lat',
        'lng',
        'location',
        'location_lat_long',
        'radius',
        'radius_unit',
    ];

    protected $casts = [
        'style_ids' => 'array',
        'tag_ids' => 'array',
        'custom_themes' => 'array',
        'allow_artist_contact' => 'boolean',
        'is_active' => 'boolean',
        'interested_by' => 'date',
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'radius' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tattoo()
    {
        return $this->hasOne(Tattoo::class, 'tattoo_lead_id');
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

    /**
     * Scope for leads within a certain radius of a point.
     * Uses bounding box for initial filter, then Haversine for accuracy.
     */
    public function scopeWithinRadius($query, float $lat, float $lng, int $radiusMiles = 50)
    {
        // Rough bounding box filter (1 degree lat ≈ 69 miles)
        $latDelta = $radiusMiles / 69;
        $lngDelta = $radiusMiles / (69 * cos(deg2rad($lat)));

        return $query
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->whereBetween('lat', [$lat - $latDelta, $lat + $latDelta])
            ->whereBetween('lng', [$lng - $lngDelta, $lng + $lngDelta])
            ->whereRaw("
                (3959 * acos(
                    cos(radians(?)) *
                    cos(radians(lat)) *
                    cos(radians(lng) - radians(?)) +
                    sin(radians(?)) *
                    sin(radians(lat))
                )) <= ?
            ", [$lat, $lng, $lat, $radiusMiles]);
    }
}
