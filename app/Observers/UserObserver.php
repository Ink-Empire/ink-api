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
        if (!in_array($user->type_id, [UserTypes::ARTIST_TYPE_ID, UserTypes::STUDIO_TYPE_ID], true)) {
            return;
        }

        // Fetch the Artist from database to include all relationships for Elasticsearch
        $artist = Artist::withoutGlobalScope(ArtistScope::class)->find($user->id);

        if (!$artist) {
            return;
        }

        // Model::searchable() indexes unconditionally, so the check Scout's own
        // observer would make has to happen here. This also pulls an account
        // back out of the index if it stops qualifying.
        if ($artist->shouldBeSearchable()) {
            $artist->searchable();
        } else {
            $artist->unsearchable();
        }
    }

    public function deleted(User $user)
    {
        if (!in_array($user->type_id, [UserTypes::ARTIST_TYPE_ID, UserTypes::STUDIO_TYPE_ID], true)) {
            return;
        }

        // The row is gone by now, so a lookup finds nothing. Scout only needs
        // the key to remove the document. Without this, deletions that do not
        // go through the account deletion endpoint leave the account visible
        // in search.
        $artist = new Artist();
        $artist->exists = true;
        $artist->forceFill(['id' => $user->id]);

        $artist->unsearchable();
    }
}
