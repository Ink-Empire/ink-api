<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudioSpotlight extends Model
{
    protected $fillable = [
        'studio_id',
        'spotlightable_type',
        'spotlightable_id',
        'display_order',
    ];

    protected $casts = [
        'display_order' => 'integer',
    ];

    public function studio()
    {
        return $this->belongsTo(Studio::class);
    }

    public function spotlightable()
    {
        return $this->morphTo();
    }

    public function getSpotlightedItemAttribute()
    {
        if ($this->spotlightable_type === 'artist') {
            return User::find($this->spotlightable_id);
        } elseif ($this->spotlightable_type === 'tattoo') {
            return Tattoo::find($this->spotlightable_id);
        }
        return null;
    }
}
