<?php

namespace App\Util;

use App\Models\Artist;
use App\Models\Studio;
use App\Models\User;

class ModelLookup
{
    /**
     * Find an artist by ID (numeric) or slug (string)
     * Loads relationships needed for full API responses
     */
    public static function findArtist($identifier, bool $withSchedule = true)
    {
        $query = Artist::query();

        if ($withSchedule) {
            $query->with(['working_hours', 'appointments', 'styles']);
        }

        if (!is_numeric($identifier)) {
            return $query->where('slug', $identifier)->first();
        }

        return $query->find($identifier);
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