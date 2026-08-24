<?php

namespace App\Observers;

use App\Jobs\SendSlackNewUserNotification;
use App\Models\User;
use App\Models\Artist;
use App\Enums\UserTypes;
use App\Scopes\ArtistScope;

class UserObserver
{
    public function created(User $user)
    {
        if ($user->is_demo) {
            return;
        }

        SendSlackNewUserNotification::dispatch($user->id);
    }

    public function saved(User $user)
    {
        if (in_array($user->type_id, [UserTypes::ARTIST_TYPE_ID, UserTypes::STUDIO_TYPE_ID], true)) {
            // Fetch the Artist from database to include all relationships for Elasticsearch
            $artist = Artist::withoutGlobalScope(ArtistScope::class)->find($user->id);
            if ($artist) {
                $artist->searchable();
            }
        }
    }
}
