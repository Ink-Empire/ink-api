<?php

namespace App\Services;

use App\Models\Artist;
use App\Util\stringToModel;

class SearchService
{

    public function __construct(protected UserService $userService)
    {
    }

    public function getById($id, $model)
    {
        $model = stringToModel::convert(ucfirst($model));

        $this->search = $model->search();

        $response = $this->search->where('id', $id)->get();

        if(!empty($response)) {
            return collect($response['response'])->first();
        }
    }

    public function search($params)
    {
        \Log::debug(json_encode($params));

        $this->filters = $params;

        if (isset($this->filters['model'])) {
            $model = ucfirst($this->filters['model']);
            $this->model = stringToModel::convert($model);
        }

        if (isset($this->filters['user_id'])) {
            $this->user = $this->userService->getById($this->filters['user_id']);
        }

        //initialize the elastic query (model for either artist or tattoo)
        $this->search = $this->model->search();

        if (isset($this->filters['studio_id'])) {
            $this->buildStudioParam();
        }

        if (isset($this->filters['styles'])) {
            $this->buildStylesParam();
        }

        if (isset($this->filters['artist_near_me'])) {
            $this->buildDistanceParam('artist.location_lat_long');
        }

        if (isset($this->filters['near_location'])) {

            //artist.location_lat_long for example
            $nestedField = $this->filters['near_location'] . '.location_lat_long';

            $this->buildDistanceParam($nestedField, $this->filters['location_lat_long']);
        }

        if (isset($this->filters['studio_near_me'])) {
            $this->buildDistanceParam('studio.location_lat_long');
        }

        if (isset($this->filters['studio_near_location'])) {
            $this->buildDistanceParam('studio.location_lat_long', $this->filters['location_lat_long']);
        }


        //$this->buildGeoSort();

        $response = $this->search->get();

        return $response;
    }

    private function buildDistanceParam($field = 'location_lat_long', string $latLongString = null)
    {
        //TODO build in support for KM
        $distance = $this->filters['distance'] . 'mi' ?? '25mi';

        if (empty($latLongString) && isset($this->user)) {
            $latLongArray = explode(",", $this->user->location_lat_long);
        } else {
            $latLongArray = explode(",", $latLongString);
        }

        $this->search->whereDistance($field, $latLongArray[0], $latLongArray[1], $distance);
    }

    private function buildGeoSort($field = 'location_lat_long', string $latLongString = null)
    {
        //TODO add filter on distances
        //we need the current User's location to get this
        try {
            if (empty($latLongString) && isset($this->user)) {
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

    private function buildStudioParam()
    {
        $this->search->where('studio.id', $this->filters['studio_id']);
    }

    private function buildStylesParam($minMatch = 1)
    {
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
