<?php

namespace App\Services;

use App\Models\Artist;
use App\Models\Tattoo;
use App\Util\stringToModel;
use Larelastic\Elastic\Facades\Elastic;

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

        if (!empty($response)) {
            return collect($response['response'])->first();
        }
    }

    public function search_tattoo($filters)
    {
        $this->search = Tattoo::search();
        $this->filters = $filters;

        if (isset($this->filters['user_id'])) {
            $this->user = $this->userService->getById($filters['user_id']);
        }

        if (isset($this->filters['search_text'])) {

            $query = Tattoo::search();

            $query->wherePrefix('description', $this->filters['search_text']);
            $query->where('tags', 'in', [$this->filters['search_text']]);

            $this->search->orWhere(
                $query, 1
            );
        }

        if (isset($this->filters['studio_id'])) {
            $this->buildStudioParam();
        }

        if (isset($this->filters['styles'])) {
            $this->buildStylesParam();
        }

        if (isset($this->filters['artist_near_me']) && $this->filters['artist_near_me']) {
            $distanceParam = 'artist.location_lat_long';
            $this->buildDistanceParam($distanceParam);
        }

        if (isset($this->filters['artist_near_location']) && $this->filters['artist_near_location']) {
            //artist.location_lat_long for example
            $nestedField = 'artist.location_lat_long';
            $this->buildDistanceParam($nestedField, $this->filters['location_lat_long']);
        }

        if (isset($this->filters['saved_artists'])) {

            $favoriteArtistIds = $this->userService->getFavoriteArtistIds($this->user->id);

            $this->search->where('artist_id', 'in', $favoriteArtistIds);
        }

        if (isset($this->filters['saved_tattoos'])) {

            $favoriteTattooIds = $this->userService->getFavoriteTattooIds($this->user->id);

            $this->search->where('id', 'in', $favoriteTattooIds);
        }

        if (isset($this->filters['studio_near_me']) && $this->filters['studio_near_me']) {
            $this->buildDistanceParam('studio.location_lat_long');
        }

        if (isset($this->filters['studio_near_location']) && $this->filters['studio_near_location']) {
            $this->buildDistanceParam('studio.location_lat_long', $this->filters['location_lat_long']);
        }

        return $this->search->get();
    }

    public function search_artist($filters)
    {
        $this->search = Artist::search();
        $this->filters = $filters;

        if (isset($this->filters['user_id'])) {
            $this->user = $this->userService->getById($filters['user_id']);
        }

        if (isset($this->filters['search_text'])) {
            $this->search->whereMulti(['name', 'about', 'studio_name'], 'or', $this->filters['search_text']);
        }

        if (isset($this->filters['studio_id'])) {
            $this->buildStudioParam();
        }

        if (isset($this->filters['styles'])) {
            $this->buildStylesParam();
        }

        if (isset($this->filters['saved_artists'])) {

            $favoriteArtistIds = $this->userService->getFavoriteArtistIds($this->user->id);

            $this->search->where('id', 'in', $favoriteArtistIds);
        }

        if (isset($this->filters['artist_near_me']) && $this->filters['artist_near_me']) {
            $distanceParam = 'location_lat_long';
            $this->buildDistanceParam($distanceParam);
        }

        if (isset($this->filters['artist_near_location']) && $this->filters['artist_near_location']) {
            $this->buildDistanceParam('location_lat_long', $this->filters['location_lat_long']);
        }

        if (isset($this->filters['studio_near_me']) && $this->filters['studio_near_me']) {
            $this->buildDistanceParam('studio.location_lat_long');
        }

        if (isset($this->filters['studio_near_location']) && $this->filters['studio_near_location']) {
            $this->buildDistanceParam('studio.location_lat_long', $this->filters['location_lat_long']);
        }

        return $this->search->get();
    }

    private function buildDistanceParam($field = 'location_lat_long', string $latLongString = null)
    {
        //TODO build in support for KM

        try {
            $distance = $this->filters['distance'] . 'mi' ?? '25mi';
            if (empty($latLongString) && isset($this->user)) {
                $latLongArray = explode(",", $this->user->location_lat_long);
            } else {
                $latLongArray = explode(",", $latLongString);
            }
            $this->search->whereDistance($field, $latLongArray[0], $latLongArray[1], $distance);
        } catch (\Exception $e) {
            \Log::error("Unable to build distance param", [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'user_id' => $this->user->id ?? "user not found",
            ]);
        }
    }

    public function initialUserResults($user_id)
    {
        //home page initial search will return tattoo results
        //either tattoos in saved styles OR artists user has saved OR artists near location, sorted by new
        $this->search = Tattoo::search();

        $this->user = $this->userService->getById($user_id);

        $this->getInitialNestedUserQuery();

        //TODO get some pagination in here
        $this->search->size($this->search->count());

        //TODO add sort for newest posted
        return $this->search->get();

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

    private function buildDistanceOrSavedArtists()
    {
        $styleSearch = Tattoo::search();
        foreach ($this->user->styles as $style) {
            if ($style) {
                $clauses[] = ['styles.id', '=', $style->id];
            }
        }

        $styleSearch->orWhere($clauses, 1);
        $styleResponse = $styleSearch->get();

        $distanceSearch = Tattoo::search();
        $distance = '25mi';
        $latLongArray = explode(",", $this->user->location_lat_long);

        $distanceSearch->whereDistance('artist.location_lat_long', $latLongArray[0], $latLongArray[1], $distance);

        $distanceResponse = $distanceSearch->get();


        return $styleResponse['response']->merge($distanceResponse['response']);

    }

    //this creates opposing queries and nests them as THIS or THAT. Prime example: WUB + 1 and 4 color cards.
    private function getInitialNestedUserQuery()
    {
        $styleClause = $this->getUserStylesOrSyntax(1);
        $savedArtistsOrSyntax = $this->getSavedArtistsOrSyntax(1);
        $artistsNearMeSyntax = $this->getArtistsNearMeSyntax();
        $minMatch = 1;

        $this->search->nestedOr(
            [
                [
                    $styleClause,
                    $savedArtistsOrSyntax,
                    $artistsNearMeSyntax
                ]
            ], $minMatch //we only care if one of these brings back results
        );
    }

    private function getUserStylesOrSyntax($minMatch = 1)
    {
        $styles_clauses = collect($this->user->styles)
            ->map(function ($value) {
                return ['styles.id', '=', $value->id];
            })->toArray();

        $response['bool']['minimum_should_match'] = $minMatch;
        $response['bool']['should'] = $this->search->orWhereSyntax($styles_clauses, $minMatch);

        return $response;
    }

    private function getSavedArtistsOrSyntax($minMatch = 1)
    {
        $faves_clauses = collect($this->user->artists)
            ->map(function ($value) {
                return ['artist_id', '=', $value->id];
            })->toArray();

        $response['bool']['minimum_should_match'] = $minMatch;
        $response['bool']['should'] = $this->search->orWhereSyntax($faves_clauses, $minMatch);

        return $response;
    }

    private function getArtistsNearMeSyntax()
    {
        $latLongArray = explode(",", $this->user->location_lat_long);

        $response['bool']['must'] = $this->search->whereDistanceSyntax('artist.location_lat_long', $latLongArray[0], $latLongArray[1], '25mi');

        return $response;
    }

}
