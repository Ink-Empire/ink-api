<?php

namespace App\Models;

use App\Enums\ArtistTattooApprovalStatus;
use App\Http\Resources\Elastic\TattooIndexResource;
use Fico7489\Laravel\Pivot\Traits\PivotEventTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Larelastic\Elastic\Traits\Migratable;
use Larelastic\Elastic\Traits\Searchable;

class Tattoo extends Model
{
    use HasFactory;
    use PivotEventTrait;

    protected $fillable = [
        'id',
        'title',
        'description',
        'placement',
        'duration',
        'artist_id',
        'studio_id',
        'primary_style_id',
        'primary_subject_id',
        'primary_image_id',
        'uploaded_by_user_id',
        'approval_status',
        'is_visible',
        'attributed_artist_name',
        'attributed_studio_name',
        'attributed_location',
        'is_demo',
    ];

    protected $casts = [
        'is_visible' => 'boolean',
        'is_demo' => 'boolean',
    ];

    protected array $searchableRelations = [
        'tags',
        'artist',
        'studio',
        'images',
        'primary_style',
        'primary_image',
        'styles',
        'uploader.image',
    ];

    protected static function booted()
    {
    }


    public function artist()
    {
        return $this->belongsTo(Artist::class)->with(['settings']);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function studio()
    {
        return $this->belongsTo(Studio::class);
    }

    //todo we need all secondary tags
    public function primary_style()
    {
        return $this->belongsTo(Style::class, 'primary_style_id', 'id');
    }

    public function styles()
    {
        return $this->belongsToMany(Style::class, 'tattoos_styles', 'tattoo_id', 'style_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'primary_subject_id', 'id');
    }

    public function primary_image()
    {
        return $this->belongsTo(Image::class, 'primary_image_id', 'id');
    }

    public function images()
    {
        return $this->belongsToMany(Image::class, 'tattoos_images', 'tattoo_id');
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class, 'tattoos_tags', 'tattoo_id', 'tag_id');
    }

    /**
     * Get only approved tags (for public display).
     */
    public function approvedTags()
    {
        return $this->belongsToMany(Tag::class, 'tattoos_tags', 'tattoo_id', 'tag_id')
                    ->where('is_pending', false);
    }

    /**
     * Get all profile views for this tattoo.
     */
    public function profileViews()
    {
        return $this->morphMany(ProfileView::class, 'viewable');
    }

    public function invitations()
    {
        return $this->hasMany(ArtistInvitation::class);
    }

    public function getIsFeaturedAttribute()
    {
        return (bool) ($this->attributes['is_featured'] ?? false);
    }

    public function scopeVisible($query)
    {
        return $query->where('is_visible', true);
    }

    public function scopePendingForArtist($query, $artistId)
    {
        return $query->where('artist_id', $artistId)->where('approval_status', ArtistTattooApprovalStatus::PENDING);
    }

    public function scopeOwnedByArtist($query, $artistId)
    {
        return $query->where('artist_id', $artistId)->where('uploaded_by_user_id', $artistId);
    }

    public function scopeTaggedByClient($query, $artistId)
    {
        return $query->where('artist_id', $artistId)->where('uploaded_by_user_id', '!=', $artistId);
    }

    /*
    * Elasticsearch
    */

    use Migratable;
    use Searchable;

    /** @var string */
    protected $indexConfigurator = TattooIndexConfigurator::class;

    /**
     * Get the index name for the model.
     */
    public function searchableAs(): string
    {
        return $this->getIndexConfigurator()->getName();
    }

    public function searchableQuery()
    {
        $query = $this->newQuery();
        $query->with(
            $this->searchableRelations
        );

        return $query;
    }

    public function shouldBeSearchable()
    {
        return $this['id'] > 0;
    }

    public function toSearchableArray()
    {
        $with = $this->searchableRelations;

        $this->loadMissing($with);

        if ($this instanceof Tattoo) {
            return (new TattooIndexResource($this))->jsonSerialize();
        } else {
            return TattooIndexResource::collection($this)->jsonSerialize();
        }
    }

}
