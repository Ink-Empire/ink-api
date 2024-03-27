<?php

namespace App\Models;


use App\Scopes\ArtistScope;

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

    //the people who favorite this artist
    public function users()
    {
        return $this->belongsToMany(User::class, 'users_artists', 'artist_id', 'user_id');
    }

    public function studio()
    {
        return $this->belongsTo(Studio::class);
    }

    public function tattoos()
    {
        return $this->belongsToMany(Tattoo::class, 'users_tattoos', 'user_id', 'tattoo_id');
    }
}
