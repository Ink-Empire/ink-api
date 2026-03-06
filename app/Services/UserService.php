<?php

namespace App\Services;

use App\Exceptions\UserNotFoundException;
use App\Models\Image;
use App\Models\Style;
use App\Models\Tattoo;
use App\Models\User;
use App\Util\ModelLookup;


/**
 *
 */
class UserService
{
    const USER_RELATIONSHIPS = [
        'styles' => Style::class,
        'tattoos' => Tattoo::class,
        'artists' => User::class
    ];

    /**
     * Get a user by their ID or username
     *
     * @param string|int $id The user ID or username
     * @return User|null
     */
    public function getById($id)
    {
        if ($id) {
            return ModelLookup::findUser($id);
        }

        return null;
    }

    /**
     * @throws UserNotFoundException
     */
    public function setProfileImage(string $user_id, Image $image): User
    {
        $user = $this->getById($user_id);

        if ($user) {
            $user->image_id = $image->id;
            $user->save();
        } else {
            throw new UserNotFoundException();
        }

        return $user;
    }

    public function updateStyles(?User $user, $stylesArray): void
    {
        $user->styles()->sync(array_filter($stylesArray));
    }

    public function updateTattoos(?User $user, mixed $tattooArray): void
    {
        if($tattooArray === null) {
            $user->tattoos()->detach();
            return;
        }

        $user->tattoos()->sync(array_filter($tattooArray));
    }

    public function updateArtists(?User $user, mixed $artistArray): void
    {
        if($artistArray === null) {
            $user->artists()->detach();
            return;
        }

        $user->artists()->sync(array_filter($artistArray));
    }

    public function getFavoriteArtistIds(mixed $id)
    {
        return User::where('id', $id)->first()->artists->pluck('id')->toArray();
    }

    public function getFavoriteTattooIds(mixed $id)
    {
        return User::where('id', $id)->first()->tattoos->pluck('id')->toArray();
    }

    /**
     * Clean up DB relationships for a user being deleted.
     * Returns profile image info for async S3 cleanup.
     *
     * @return array|null ['filename' => string, 'id' => int] or null
     */
    public function cleanupPostDelete(User $user): ?array
    {
        // Revoke all API tokens
        $user->tokens()->delete();

        // Delete hasMany relationships
        $user->passwords()->delete();
        $user->socialMediaLinks()->delete();
        $user->tattooLeads()->delete();
        $user->artistWishlists()->delete();
        $user->conversationParticipants()->delete();
        $user->profileViews()->delete();

        // Delete artist settings if exists
        if ($user->settings) {
            $user->settings()->delete();
        }

        // Delete calendar connection if exists
        if ($user->calendarConnection) {
            $user->calendarConnection()->delete();
        }

        // Detach many-to-many relationships
        $user->styles()->detach();
        $user->tattoos()->detach();
        $user->artists()->detach();
        $user->wishlistArtists()->detach();
        $user->affiliatedStudios()->detach();
        $user->blockedUsers()->detach();
        $user->blockedByUsers()->detach();
        $user->conversations()->detach();

        // Handle owned studio - remove ownership but keep studio
        if ($user->ownedStudio) {
            $user->ownedStudio->update([
                'owner_id' => null,
                'is_claimed' => false,
            ]);
        }

        // Detach profile image from user, return info for async S3 cleanup
        $profileImageInfo = null;
        if ($user->image_id && $user->image) {
            $profileImageInfo = [
                'filename' => $user->image->filename,
                'id' => $user->image->id,
            ];
            $user->update(['image_id' => null]);
        }

        return $profileImageInfo;
    }
}
