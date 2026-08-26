<?php

namespace App\Policies;

use App\Models\Studio;
use App\Models\User;

class StudioPolicy
{
    /**
     * Whether the user may manage a studio: its details, its roster, and
     * anything published under the studio's own name.
     *
     * Both a claiming owner and a studio-type account hold their studio
     * through owner_id, so ownership is the single rule. Artists verified at
     * the studio deliberately do not qualify - speaking as the studio is not
     * the same as working there.
     */
    public function manage(User $user, Studio $studio): bool
    {
        if ($studio->owner_id === null) {
            return false;
        }

        // Cast both sides: an id can arrive as an int from a fresh query and as
        // a string from a model built in memory, so a strict compare would deny
        // a genuine owner.
        return (int) $studio->owner_id === (int) $user->id;
    }
}
