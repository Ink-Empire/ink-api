<?php

namespace App\Enums;

class UserTypes
{
    const USER = 'user';
    const ARTIST = 'artist';
    const STUDIO = 'studio';

    const CLIENT_TYPE_ID = 1;
    const ARTIST_TYPE_ID = 2;
    const STUDIO_TYPE_ID = 3;

    /**
     * Get type_id from type string
     */
    public static function getTypeId(string $type): int
    {
        return match ($type) {
            self::ARTIST => self::ARTIST_TYPE_ID,
            self::STUDIO => self::STUDIO_TYPE_ID,
            default => self::CLIENT_TYPE_ID,
        };
    }
}
