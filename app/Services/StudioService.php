<?php

namespace App\Services;

use App\Exceptions\UserNotFoundException;
use App\Models\Artist;
use App\Models\Image;
use App\Models\Studio;

/**
 *
 */
class StudioService
{

    /**
     * @param int $id
     */
    public function getById(int $id) : ?Studio
    {
        if ($id) {
            return Studio::where('id', $id)->first();
        }

        return null;
    }

    /**
     *
     */
    public function get()
    {
        return Studio::paginate(25);
    }


    /**
     * @throws UserNotFoundException
     */
    public function setStudioImage(string $studio_id, Image $image): Studio
    {
        $studio = $this->getById($studio_id);

        if ($studio) {
            $studio->image_id = $image->id;
            $studio->save();
        } else {
            throw new UserNotFoundException();
        }

        return $studio;
    }
}
