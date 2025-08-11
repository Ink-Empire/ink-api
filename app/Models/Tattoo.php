<?php

namespace App\Models;

use App\Http\Resources\Elastic\Primary\TattooResource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Larelastic\Elastic\Traits\Migratable;
use Larelastic\Elastic\Traits\Searchable;

class Tattoo extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'title',
        'description',
        'placement',
        'artist_id',
        'studio_id',
        'primary_style_id',
        'primary_subject_id',
        'primary_image_id',
    ];

    public function artist()
    {
        return $this->belongsTo(Artist::class)->with(['settings']);
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
        return $this->hasMany(Tag::class);
    }

    /*
    * Elasticsearch
    */

    use Migratable;
    use Searchable;

    /** @var string */
    protected $indexConfigurator = TattooIndexConfigurator::class;

    public function searchableQuery()
    {
        $query = $this->newQuery();
        $query->with([
            'artist',
            'studio',
            'images',
            'primary_style',
            'styles',
            'tags'
        ]);

        return $query;
    }

    public function shouldBeSearchable()
    {
        return $this['id'] > 0;
    }

    public function toSearchableArray()
    {
        $with = [
            'artist',
            'studio',
            'images',
            'primary_style',
            'styles',
            'tags',
        ];

        $this->loadMissing($with);

        if ($this instanceof Tattoo) {
            return (new TattooResource($this))->jsonSerialize();
        } else {
            return TattooResource::collection($this)->jsonSerialize();
        }
    }

}
