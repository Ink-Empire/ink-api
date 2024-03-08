<?php

namespace App\Services;

use App\Exceptions\UserNotFoundException;
use App\Models\Image;
use App\Models\User;

/**
 *
 */
class UserService
{
    /**
     * @param int $id
     * @return void|User
     */
    public function getById(int $id)
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
}
