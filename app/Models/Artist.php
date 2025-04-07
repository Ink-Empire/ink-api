<?php

namespace App\Models;


use App\Enums\UserTypes;
use App\Http\Resources\Elastic\Primary\ArtistResource;
use App\Scopes\ArtistScope;
use Larelastic\Elastic\Traits\Migratable;
use Larelastic\Elastic\Traits\Searchable;

class Artist extends User
{
    public $table = 'users';

    /**
     * The "booted" method of the model.
     *
     * @return void
     */
    protected static function booted(): void
    {
        static::addGlobalScope(new ArtistScope());
    }

    /**
     * Get the index name for the model.
     *
     * @return string
     */
    public function searchableAs()
    {
        return 'artists';
    }

    //the people who favorite this artist
    public function users()
    {
        return $this->belongsToMany(User::class, 'users_artists', 'artist_id', 'user_id');
    }

    public function studio()
    {
        return $this->belongsTo(Studio::class);
    }

    public function styles()
    {
        return $this->belongsToMany(Style::class, 'artists_styles', 'artist_id', 'style_id');
    }

    public function tattoos()
    {
        return $this->hasMany(Tattoo::class, 'artist_id', 'id');
    }

    public function primary_image()
    {
        return $this->belongsTo(Image::class, 'image_id', 'id');
    }

    /*
    * Elasticsearch
    */

    use Migratable;
    use Searchable;

    /** @var string */
    protected $indexConfigurator = ArtistIndexConfigurator::class;

    public function searchableQuery()
    {
        $query = $this->newQuery();
        $query->with([
            'studio',
            'styles',
            'tattoos',
        ]);

        return $query;
    }

    public function shouldBeSearchable()
    {
        return $this['type_id'] === UserTypes::ARTIST_TYPE_ID;
    }

    public function toSearchableArray()
    {
        $with = [
            'studio',
            'styles',
            'tattoos',
            'primary_image'
        ];

        $this->loadMissing($with);

        if ($this instanceof Artist) {
            return (new ArtistResource($this))->jsonSerialize();
        } else {
            return ArtistResource::collection($this)->jsonSerialize();
        }
    }
}
