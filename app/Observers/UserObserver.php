<?php

namespace App\Observers;

use App\Models\User;
use App\Models\Artist;
use App\Enums\UserTypes;

class UserObserver
{
    public function saved(User $user)
    {
        if ($user->type_id === UserTypes::ARTIST_TYPE_ID) {
            // Cast the User instance to Artist for elastic
            $artist = new Artist($user->getAttributes());

            $artist->searchable();
        }
    }
}
