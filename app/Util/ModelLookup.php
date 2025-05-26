<?php

namespace App\Util;

use App\Models\Artist;
use App\Models\Studio;
use App\Models\User;

class ModelLookup
{
    /**
     * Find an artist by ID (numeric) or slug (string)
     */
    public static function findArtist($identifier)
    {
        if (!is_numeric($identifier)) {
            return Artist::where('slug', $identifier)->first();
        }
        
        return Artist::find($identifier);
    }

    /**
     * Find a studio by ID (numeric) or slug (string)
     */
    public static function findStudio($identifier)
    {
        if (!is_numeric($identifier)) {
            return Studio::where('slug', $identifier)->first();
        }
        
        return Studio::find($identifier);
    }

    /**
     * Find a user by ID (numeric) or username (string)
     */
    public static function findUser($identifier)
    {
        if (!is_numeric($identifier)) {
            return User::where('username', $identifier)->first();
        }
        
        return User::find($identifier);
    }
}