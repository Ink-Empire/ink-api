<?php

namespace App\Services;


use App\Exceptions\TattooNotFoundException;
use App\Models\Tattoo;

/**
 *
 */
class TattooService
{
    private $filters = [];
    private $search;
    private $user;

    /**
     * @param int $id
     * @return void|Tattoo
     */
    public function get()
    {
        return Tattoo::paginate(25);
    }

    public function search($params)
    {
        $this->filters = $params;

        //initialize the elastic query
        $this->search = Tattoo::search();

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

        return $this->search->get();
    }

    /**
     * @param int $id
     * @return void|Tattoo
     */
    public function getById(int $id)
    {
        if ($id) {
            return Tattoo::where('id', $id)->first();
        }

        return null;
    }

    public function setPrimaryImage($id, $image)
    {
        $tattoo = $this->getById($id);

        if ($tattoo) {
            $tattoo->primary_image_id = $image->id;
            $tattoo->save();
        } else {
            throw new TattooNotFoundException();
        }

        return $tattoo;
    }

    //todo move to either trait or searchService
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
        }
        if (count($clauses) > 0) {
            $this->search->orWhere($clauses, $minMatch);
        }
    }
}
