<?php

namespace App\Notifications\Traits;

use App\Enums\UserTypes;
use InvalidArgumentException;

/**
 * Picks which version of an email an account should receive.
 *
 * Shared so the rule lives in one place. A welcome used to decide this from an
 * is-artist boolean, which sorted every studio owner into the client branch,
 * and the same mistake is easy to repeat in a second email. An unrecognised
 * type throws rather than falling back, so a fourth account type surfaces as a
 * failed job instead of somebody quietly reading copy written for a different
 * audience.
 */
trait ResolvesAccountAudience
{
    /**
     * @return list<string>
     */
    protected static function knownAudiences(): array
    {
        return [UserTypes::CLIENT, UserTypes::ARTIST, UserTypes::STUDIO];
    }

    protected static function assertKnownAudience(?string $audience): void
    {
        if ($audience !== null && ! in_array($audience, self::knownAudiences(), true)) {
            throw new InvalidArgumentException(static::class." has no copy for audience {$audience}.");
        }
    }

    /**
     * $forced is only set by the admin preview, which sends to a bare address
     * and so has no account to read a type from.
     */
    protected function audienceFor(object $notifiable, ?string $forced = null): string
    {
        if ($forced !== null) {
            return $forced;
        }

        return match ($notifiable->type_id ?? null) {
            UserTypes::CLIENT_TYPE_ID => UserTypes::CLIENT,
            UserTypes::ARTIST_TYPE_ID => UserTypes::ARTIST,
            UserTypes::STUDIO_TYPE_ID => UserTypes::STUDIO,
            default => throw new InvalidArgumentException(
                static::class." has no copy for type_id {$notifiable->type_id}."
            ),
        };
    }
}
