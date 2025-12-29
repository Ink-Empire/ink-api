<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class Studio extends Model
{
    use HasFactory;

    protected $touches = ['artists'];

    protected $with = ['image'];

    protected $fillable = [
        'name',
        'slug',
        'address_id',
        'image_id',
        'about',
        'location',
        'location_lat_long',
        'email',
        'password',
        'phone',
        'owner_id',
        'seeking_guest_artists',
        'guest_spot_details',
    ];

    protected $casts = [
        'seeking_guest_artists' => 'boolean',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function address()
    {
        return $this->belongsTo(Address::class);
    }

    public function image()
    {
        return $this->belongsTo(Image::class);
    }

    public function styles()
    {
        return $this->belongsToMany(Style::class, 'studios_styles', 'studio_id', 'style_id');
    }

    public function artists()
    {
        return $this->belongsToMany(User::class, 'users_studios', 'studio_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * Get all tattoos from artists associated with this studio.
     * Cached for 15 minutes.
     */
    public function getTattoos()
    {
        return Cache::remember("studio_{$this->id}_tattoos", 900, function () {
            $artistIds = $this->artists()->pluck('users.id');
            return Tattoo::whereIn('user_id', $artistIds)->get();
        });
    }

    /**
     * Clear the cached tattoos for this studio.
     */
    public function clearTattoosCache(): void
    {
        Cache::forget("studio_{$this->id}_tattoos");
    }

    public function business_hours()
    {
        return $this->hasMany(BusinessHours::class);
    }

    public function announcements()
    {
        return $this->hasMany(StudioAnnouncement::class);
    }

    public function activeAnnouncements()
    {
        return $this->hasMany(StudioAnnouncement::class)->where('is_active', true);
    }

    public function spotlights()
    {
        return $this->hasMany(StudioSpotlight::class)->orderBy('display_order');
    }

    /**
     * Get all profile views for this studio.
     */
    public function profileViews()
    {
        return $this->morphMany(ProfileView::class, 'viewable');
    }
}
