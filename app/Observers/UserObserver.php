<?php

namespace App\Observers;

use App\Models\User;
use App\Models\Artist;
use App\Enums\UserTypes;
use App\Services\SlackService;

class UserObserver
{
    public function __construct(
        protected SlackService $slackService
    ) {}

    public function created(User $user)
    {
        if ($user->is_demo) {
            return;
        }

        $this->slackService->notifyNewUser($user);
    }

    public function saved(User $user)
    {
        if ($user->type_id === UserTypes::ARTIST_TYPE_ID) {
            // Cast the User instance to Artist for elastic
            $artist = new Artist($user->getAttributes());

            $artist->searchable();
        }
    }
}
