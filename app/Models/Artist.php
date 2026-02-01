<?php

namespace App\Models;


use App\Enums\UserTypes;
use App\Http\Resources\Elastic\ArtistIndexResource;
use App\Scopes\ArtistScope;
use Larelastic\Elastic\Traits\Migratable;
use Larelastic\Elastic\Traits\Searchable;

class Artist extends User
{
    public $table = 'users';

    public $with = ['settings'];

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
        return $this->getIndexConfigurator()->getName();
    }

    //the people who favorite this artist
    public function users()
    {
        return $this->belongsToMany(User::class, 'users_artists', 'artist_id', 'user_id');
    }

    public function settings()
    {
        return $this->hasOne(ArtistSettings::class, 'artist_id', 'id');
    }

    /**
     * Get the artist's primary studio (from verified affiliations).
     * Overrides User::studio() for Elasticsearch compatibility.
     */
    public function studio()
    {
        return $this->primaryStudio();
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

    public function working_hours()
    {
        return $this->hasMany(ArtistAvailability::class, 'artist_id', 'id');
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class, 'artist_id', 'id');
    }

    public function getBooksOpenAttribute()
    {
        return $this->settings?->books_open ?? false;
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
            'primaryStudio.image',
            'styles',
            'primary_image',
            'settings',
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
            'styles',
            'primary_image',
            'settings',
        ];

        $this->loadMissing($with);

        // Get primary studio separately since it's from a pivot relationship
        $primaryStudio = $this->primary_studio;

        $result = (new ArtistIndexResource($this, $primaryStudio))->jsonSerialize();

        // Debug: log if is_demo is missing
        if (!isset($result['is_demo'])) {
            \Log::warning('Artist toSearchableArray missing is_demo', [
                'id' => $this->id,
                'is_demo_attr' => $this->is_demo,
                'attributes' => $this->getAttributes(),
            ]);
        }

        return $result;
    }
}
