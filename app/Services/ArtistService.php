<?php

namespace App\Services;

use App\Exceptions\UserNotFoundException;
use App\Models\Artist;
use App\Models\Image;

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
