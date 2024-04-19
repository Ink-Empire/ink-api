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

    private $filters = [];
    private $search;

    public function __construct(
        protected UserService $userService
    )
    {
    }

    /**
     * @param int $id
     * @return void|Artist
     */
    public function get()
    {
        return Artist::paginate(25);
    }

    public function search($params)
    {
        $this->filters = $params;

        if(isset($this->filters['user_id'])) {
            $this->user = $this->userService->getById($this->filters['user_id']);
        }

        //initialize the elastic query
        $this->search = Artist::search();

        if (isset($this->filters['studio_id'])) {
            $this->buildStudioParam();
        }

        if (isset($this->filters['styles'])) {
            $this->buildStylesParam();
        }

        if (isset($this->filters['near_me'])) {
            $this->buildGeoParam();
        }

        if (isset($this->filters['near_location'])) {
            $this->buildGeoParam('location_lat_long', $this->filters['near_location']);
        }

        if (isset($this->filters['studio_near_me'])) {
            $this->buildGeoParam('studio.location_lat_long');
        }

        if (isset($this->filters['studio_near_location'])) {
            $this->buildGeoParam('studio.location_lat_long', $this->filters['studio_near_location']);
        }

        $this->search->sort('studio.id', 'desc');

        $response = $this->search->get();

        return $response;

    }

    /**
     * @param int $id
     * @return void|Artist
     */
    public function getById($id)
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
