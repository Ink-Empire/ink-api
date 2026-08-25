<?php

namespace App\Models;

use App\Enums\StudioPostType;
use App\Enums\StudioTemplate;

use App\Enums\UserTypes;
use App\Http\Resources\Elastic\ArtistIndexResource;
use App\Http\Resources\Elastic\StudioIndexResource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Larelastic\Elastic\Traits\Migratable;
use Larelastic\Elastic\Traits\Searchable;

class Studio extends Model
{
    use HasFactory;

    protected $touches = ['artists'];

    protected $with = ['image', 'address', 'availability'];

    protected $fillable = [
        'name',
        'slug',
        'address_id',
        'image_id',
        'banner_image_id',
        'template',
        'about',
        'location',
        'location_lat_long',
        'email',
        'password',
        'phone',
        'website',
        'owner_id',
        'seeking_guest_artists',
        'guest_spot_details',
        'is_claimed',
        'google_place_id',
        'rating',
    ];

    protected $casts = [
        'template' => StudioTemplate::class,
        'seeking_guest_artists' => 'boolean',
        'is_claimed' => 'boolean',
        'rating' => 'decimal:1',
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

    /**
     * The wide header image on the public studio page, distinct from the
     * square studio mark held by image().
     */
    public function bannerImage()
    {
        return $this->belongsTo(Image::class, 'banner_image_id');
    }

    public function styles()
    {
        return $this->belongsToMany(Style::class, 'studios_styles', 'studio_id', 'style_id');
    }

    public function artists()
    {
        return $this->belongsToMany(User::class, 'artists_studios', 'studio_id', 'user_id')
            ->withPivot('is_verified', 'verified_at', 'initiated_by')
            ->withTimestamps();
    }

    /**
     * Get only verified artists for this studio.
     */
    public function verifiedArtists()
    {
        return $this->belongsToMany(User::class, 'artists_studios', 'studio_id', 'user_id')
            ->withPivot('is_verified', 'verified_at', 'initiated_by')
            ->wherePivot('is_verified', true)
            ->withTimestamps();
    }

    /**
     * Get only pending (unverified) artists for this studio.
     */
    public function pendingArtists()
    {
        return $this->belongsToMany(User::class, 'artists_studios', 'studio_id', 'user_id')
            ->withPivot('is_verified', 'verified_at', 'initiated_by')
            ->wherePivot('is_verified', false)
            ->withTimestamps();
    }

    /**
     * Get artists invited by this studio (awaiting artist acceptance).
     */
    public function pendingInvitations()
    {
        return $this->belongsToMany(User::class, 'artists_studios', 'studio_id', 'user_id')
            ->withPivot('is_verified', 'verified_at', 'initiated_by')
            ->wherePivot('is_verified', false)
            ->wherePivot('initiated_by', 'studio')
            ->withTimestamps();
    }

    /**
     * Get artists who requested to join this studio (awaiting studio approval).
     */
    public function pendingRequests()
    {
        return $this->belongsToMany(User::class, 'artists_studios', 'studio_id', 'user_id')
            ->withPivot('is_verified', 'verified_at', 'initiated_by')
            ->wherePivot('is_verified', false)
            ->wherePivot('initiated_by', 'artist')
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

    public function availability()
    {
        return $this->hasMany(StudioAvailability::class)->orderBy('day_of_week');
    }

    /**
     * Opening hours in the shape the web and mobile clients render.
     *
     * Sourced from studio_availability, which is the table the dashboard
     * writes. The old business_hours table is no longer read.
     *
     * @return list<array<string, mixed>>
     */
    public function formattedHours(): array
    {
        $dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

        return $this->availability
            ->map(function (StudioAvailability $slot) use ($dayNames) {
                $day = $dayNames[$slot->day_of_week] ?? '';

                if ($slot->is_day_off) {
                    return [
                        'day' => $day,
                        'day_id' => $slot->day_of_week,
                        'open_time' => null,
                        'close_time' => null,
                        'hours' => 'Closed',
                    ];
                }

                $open = date('g:i A', strtotime($slot->start_time));
                $close = date('g:i A', strtotime($slot->end_time));

                return [
                    'day' => $day,
                    'day_id' => $slot->day_of_week,
                    'open_time' => $slot->start_time,
                    'close_time' => $slot->end_time,
                    'hours' => "{$open} - {$close}",
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Everything the studio has published: announcements and guides alike.
     */
    public function posts()
    {
        return $this->hasMany(StudioPost::class);
    }

    /**
     * Announcements the studio manages in its dashboard, newest first.
     */
    public function announcements()
    {
        return $this->hasMany(StudioPost::class)->announcements()->latest();
    }

    /**
     * Announcements visitors see, newest first: the studio page leads with the
     * most recent one and quietens the rest.
     */
    public function activeAnnouncements()
    {
        return $this->hasMany(StudioPost::class)->announcements()->visible()->latest();
    }

    /**
     * Guides the studio has written, such as aftercare instructions.
     */
    public function guides()
    {
        return $this->hasMany(StudioPost::class)->guides();
    }

    /**
     * The aftercare guide sent to a client after their appointment.
     *
     * A studio can write several; one is flagged as the one to send. Without a
     * flag the most recent published aftercare guide stands in, so a studio
     * that wrote one and never thought about the flag still gets it sent.
     */
    public function defaultAftercareGuide(): ?StudioPost
    {
        return $this->posts()
            ->where('type', StudioPostType::Aftercare->value)
            ->visible()
            ->orderByDesc('is_default')
            ->orderByDesc('published_at')
            ->first();
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

    /**
     * Get all search impressions for this studio.
     */
    public function searchImpressions()
    {
        return $this->morphMany(SearchImpression::class, 'impressionable');
    }

    /**
     * Scope to get only claimed studios.
     */
    public function scopeClaimed($query)
    {
        return $query->where('is_claimed', true);
    }

    /**
     * Scope to get only unclaimed studios.
     */
    public function scopeUnclaimed($query)
    {
        return $query->where('is_claimed', false);
    }

    /*
* Elasticsearch
*/

    use Migratable;
    use Searchable;

    /** @var string */
    protected $indexConfigurator = StudioIndexConfigurator::class;

    public function searchableQuery()
    {
        return $this->newQuery()->with([
            'styles',
            'image',
        ]);
    }

    public function shouldBeSearchable()
    {
        return true;
    }

    public function toSearchableArray()
    {
        $with = [
            'styles',
            'image',
        ];

        $this->loadMissing($with);

        $result = (new StudioIndexResource($this))->jsonSerialize();

        // Debug: log if is_demo is missing
        if (!isset($result['is_demo'])) {
            \Log::warning('Studio toSearchableArray missing is_demo', [
                'id' => $this->id,
                'is_demo_attr' => $this->is_demo,
                'attributes' => $this->getAttributes(),
            ]);
        }

        return $result;
    }
}
