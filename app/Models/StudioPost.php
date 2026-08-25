<?php

namespace App\Models;

use App\Enums\StudioPostStatus;
use App\Enums\StudioPostType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Anything a studio publishes on its page: announcements and guides alike.
 *
 * They share a publishing envelope and differ only in the type-specific
 * columns - dates for announcements, the default flag for guides.
 */
class StudioPost extends Model
{
    protected $fillable = [
        'studio_id',
        'type',
        'title',
        'slug',
        'excerpt',
        'content',
        'media_id',
        'status',
        'published_at',
        'starts_at',
        'ends_at',
        'is_default',
        'meta_title',
        'meta_description',
        'is_active',
    ];

    protected $casts = [
        'type' => StudioPostType::class,
        'status' => StudioPostStatus::class,
        'is_active' => 'boolean',
        'is_default' => 'boolean',
        'published_at' => 'datetime',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function studio(): BelongsTo
    {
        return $this->belongsTo(Studio::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Image::class, 'media_id');
    }

    /**
     * Timely studio news.
     */
    public function scopeAnnouncements(Builder $query): Builder
    {
        return $query->whereIn('type', StudioPostType::announcementValues());
    }

    /**
     * Evergreen writing such as aftercare instructions.
     */
    public function scopeGuides(Builder $query): Builder
    {
        return $query->whereNotIn('type', StudioPostType::announcementValues());
    }

    /**
     * What a visitor is allowed to see.
     *
     * is_active is the switch the studio dashboard has always used; status and
     * the date window are the newer controls. A post has to satisfy all three.
     */
    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('status', StudioPostStatus::Published->value)
            ->where(fn (Builder $q) => $q->whereNull('published_at')->orWhere('published_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', now()));
    }
}
