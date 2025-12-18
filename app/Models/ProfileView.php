<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProfileView extends Model
{
    protected $fillable = [
        'viewer_id',
        'viewable_type',
        'viewable_id',
        'ip_address',
        'user_agent',
        'referrer',
    ];

    /**
     * Get the parent viewable model (User/Artist, Tattoo, or Studio).
     */
    public function viewable()
    {
        return $this->morphTo();
    }

    /**
     * Get the viewer (user who viewed the profile).
     */
    public function viewer()
    {
        return $this->belongsTo(User::class, 'viewer_id');
    }

    /**
     * Scope to get views for a specific viewable.
     */
    public function scopeForViewable($query, $type, $id)
    {
        return $query->where('viewable_type', $type)
            ->where('viewable_id', $id);
    }

    /**
     * Scope to get views within a date range.
     */
    public function scopeInDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    /**
     * Scope to get views from the last N days.
     */
    public function scopeLastDays($query, $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Scope to get unique views (by viewer or IP).
     */
    public function scopeUniqueViews($query)
    {
        return $query->selectRaw('viewable_type, viewable_id, COALESCE(viewer_id, ip_address) as unique_viewer')
            ->groupBy('viewable_type', 'viewable_id', 'unique_viewer');
    }
}
