<?php

namespace App\Services;

use App\Exceptions\UserNotFoundException;
use App\Models\Artist;
use App\Scopes\ArtistScope;
use App\Models\Image;
use Illuminate\Database\Eloquent\Collection;

/**
 *
 */
class ArtistService extends SearchService
{
    public function __construct(
        protected UserService $userService,
        protected PaginationService $paginationService
    ) {
        parent::__construct($userService, $paginationService);
    }

    /**
     * @return void|Artist
     */
    public function get()
    {
        return Artist::paginate(25);
    }

    /**
     * Get the search context for this service
     */
    protected function getSearchContext(): string
    {
        return 'artist';
    }

    /**
     * Initialize the search object for artists
     */
    protected function initializeSearch()
    {
        $this->search = Artist::search();
    }

    /**
     * Rank people who signed up above studios imported from Google Places.
     *
     * Sorting runs before relevance here, so this is a sort key rather than a
     * boost. Imported studio records carry no is_platform_account field and the
     * sort builder places missing values last, which is the behaviour we want.
     * It is applied ahead of the shared sorts so it takes precedence on every
     * option, not just the default.
     */
    protected function applySorting(): void
    {
        $this->search->sort('is_platform_account', 'desc');

        parent::applySorting();
    }

    /**
     * Apply artist-specific filters
     */
    protected function applySpecificFilters()
    {
        if (isset($this->filters['searchString'])) {
            $this->buildArtistSearchStringFilter();
        }

        if (isset($this->filters['booksOpen']) && $this->filters['booksOpen'] === true) {
            $this->search->where('settings.books_open', 'in', [true]);
        }
    }

    /**
     * Build search string filter for artist-specific fields
     */
    private function buildArtistSearchStringFilter()
    {
        $searchFields = [
            'name',
            'studio_name',
            // 'username' // TODO: fix username field when available
        ];

        // Use the shared method from base class
        $this->buildSearchStringFilter('Artist', $searchFields);
    }


    /**
     * @param int $id
     * @param string $model
     * @return void|Artist
     */
    public function getById($id, $model = 'artist')
    {
        return parent::getById($id, $model);
    }

    /**
     * Find artists within a given radius of a coordinate point using Elasticsearch.
     *
     * Queries the artist index's location_lat_long geo_point and rehydrates
     * Eloquent Artist models from the matching IDs.
     *
     * @param  float  $lat
     * @param  float  $lng
     * @param  string $distance Distance with unit suffix (e.g. "50mi", "25km")
     * @param  int    $limit
     * @return Collection<int, Artist>
     */
    public function getNearby(float $lat, float $lng, string $distance = '50mi', int $limit = 50): Collection
    {
        $results = Artist::search()
            ->whereDistance('location_lat_long', $lat, $lng, $distance)
            ->take($limit)
            ->get();

        $ids = collect($results['response'] ?? $results)
            ->pluck('id')
            ->filter()
            ->all();

        // The index holds studio accounts too, so rehydrate without the scope
        // or they are dropped after the geo search has already matched them.
        return Artist::withoutGlobalScope(ArtistScope::class)
            ->whereIn('id', $ids)
            ->get();
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
