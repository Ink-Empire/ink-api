<?php

namespace App\Enums;

use App\Models\Style;
use App\Models\Tattoo;
use App\Models\User;

class UserRelationships
{
    const RELATIONSHIPS = [
        'style' => 'styles',
        'tattoo' => 'tattoos',
        'artist' => 'artists',
        'studio' => 'studios'
    ];

    public static function getRelationship($relationship): ?string
    {
        return self::RELATIONSHIPS[$relationship] ?? null;
    }
}
