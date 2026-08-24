<?php

namespace App\Util;

use App\Enums\UserTypes;
use App\Models\Artist;
use App\Models\Studio;
use App\Models\User;
use App\Scopes\ArtistScope;

class ModelLookup
{
    /**
     * Find an artist or studio account by ID (numeric) or slug (string)
     * Loads relationships needed for full API responses
     *
     * ArtistScope pins queries to type_id = 2, which left studio accounts
     * unable to load their own profile and settings. Clients stay excluded.
     */
    public static function findArtist($identifier, bool $withSchedule = true)
    {
        $query = Artist::withoutGlobalScope(ArtistScope::class)
            ->whereIn('type_id', [
                UserTypes::ARTIST_TYPE_ID,
                UserTypes::STUDIO_TYPE_ID,
            ]);

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