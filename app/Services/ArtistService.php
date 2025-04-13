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
    private $user;

    public function __construct(
        protected UserService $userService
    )
    {
    }

    /**
     * @return void|Artist
     */
    public function get()
    {
        return Artist::paginate(25);
    }

    public function search($params)
    {
        $this->filters = $params;

        if (isset($this->filters['user_id'])) {
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

        //TODO in future let user decide their preference, always closest?
        // $this->search->geoSort('studio.id', 'desc');

        $response = $this->search->get();

        return $response;

    }

    private function buildGeoParam($field = 'location_lat_long', string $latLongString = null): void
    {
        //TODO add filter on distances
        //we need the current User's location to get this
        try {
            if (empty($latLongString)) {
                $latLongArray = explode(",", $this->user->location_lat_long);
            } else {
                $latLongArray = explode(",", $latLongString);
            }

            $data = [
                'field' => $field,
                'lat' => $latLongArray[0],
                'lon' => $latLongArray[1]
            ];

            $this->search->geoSort($data);

        } catch (\Exception $e) {
            \Log::error("Unable to build geo param", [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'user_id' => $this->user->id ?? "user not found",
            ]);
        }
    }

    protected function buildStudioParam(): void
    {
        $this->search->where('studio.id', $this->filters['studio_id']);
    }

    private function buildStylesParam($minMatch = 1): void
    {
        $clauses = [];

        //if exact, can set minMatch to count of styles
        foreach ($this->filters['styles'] as $style) {
            if ($style) {
                $clauses[] = ['styles.id', '=', $style];
            }

            if (count($clauses) > 0) {
                $this->search->orWhere($clauses, $minMatch);
            }
        }
    }

    /**
     * @param int $id
     * @return void|Artist
     */
    public function getById($id)
    {
        if ($id) {
            $this->search = Artist::search();

            $this->search->where('id', $id);

            $response = $this->search->get();

            return collect($response)->first();
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
