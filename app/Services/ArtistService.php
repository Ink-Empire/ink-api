<?php

namespace App\Services;

use App\Exceptions\UserNotFoundException;
use App\Models\Artist;
use App\Models\Image;

/**
 *
 */
class ArtistService
{

    /**
     * @param int $id
     * @return void|Artist
     */
    public function get()
    {
        return Artist::paginate(25);
    }

    /**
     * @param int $id
     * @return void|Artist
     */
    public function getById(int $id)
    {
        if ($id) {
            return Artist::where('id', $id)->first();
        }

        return null;
    }

    /**
     * @throws UserNotFoundException
     */
    public function setProfileImage(string $artist_id, Image $image): Artist
    {
        $artist = $this->getById($artist_id);

        if ($artist) {
            $artist->image_id = $image->id;
            $artist->save();
        } else {
            throw new UserNotFoundException();
        }

        return $artist;
    }
}
