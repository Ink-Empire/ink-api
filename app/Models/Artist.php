<?php

namespace App\Models;


class Artist extends User
{
    public $table = 'users';

    //the people who favorite this artist
    public function users()
    {
        return $this->belongsToMany(User::class, 'users_artists', 'artist_id', 'user_id');
    }

    public function studio()
    {
        return $this->belongsTo(Studio::class);
    }
}
