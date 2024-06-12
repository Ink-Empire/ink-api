<?php

namespace App\Services;

use App\Exceptions\UserNotFoundException;
use App\Models\Image;
use App\Models\Style;
use App\Models\Tattoo;
use App\Models\User;

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
     * @param $id
     * @return void|User
     */
    public function getById($id)
    {
        if ($id) {
            return User::where('id', $id)->first();
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
}
