<?php

namespace App\Enums;

use App\Models\Style;
use App\Models\Tattoo;
use App\Models\User;

class UserRelationships
{
    const RELATIONSHIPS = [
        'styles' => Style::class,
        'tattoos' => Tattoo::class,
        'artists' => User::class
    ];
}
