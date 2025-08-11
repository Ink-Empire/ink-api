<?php

namespace App\Services;

use App\Exceptions\TattooNotFoundException;
use App\Models\Tattoo;
use App\Models\User;

/**
 *
 */
class TattooService extends SearchService
{
    public function __construct(
        protected UserService $userService,
        public ImageService $imageService
    ) {
        parent::__construct($userService);
    }

    public function upload(array $files, User $user): Array
    {
        $images = [];

        foreach ($files as $file) {
            $date = Date('Ymdi');

            //get image extension
            $extension = $file->getClientOriginalExtension() ?: 'jpeg';

            $filename = "tattoo_" . $user->id . "_" . $date . "." . $extension;
            $image = $this->imageService->processImage($file, $filename);

            if ($image) {
                $images[] = $image;
            } else {
                throw new \Exception("Unable to process image");
            }
        }
        return $images;
    }

    /**
     * Get the search context for this service
     */
    protected function getSearchContext(): string
    {
        return 'tattoo';
    }

    /**
     * Initialize the search object for tattoos
     */
    protected function initializeSearch()
    {
        $this->search = Tattoo::search();
    }

    /**
     * Apply tattoo-specific filters
     */
    protected function applySpecificFilters()
    {
        if (isset($this->filters['searchString'])) {
            $this->buildTattooSearchStringFilter();
        }

        if(isset($this->filters['booksOpen'])) {
            // Filter tattoos by whether the artist's books are open
            $this->checkBooksOpen();
        }
    }

    private function checkBooksOpen()
    {
        // Check if the artist's books are open
        $this->search->where('artist_books_open', true);
    }

    /**
     * Build search string filter for tattoo-specific fields
     */
    private function buildTattooSearchStringFilter()
    {
        $searchFields = [
            'description',
            'artist_name',  // Search in artist name
            'studio_name'   // Search in studio name
        ];

        // Use the shared method from base class
        $this->buildSearchStringFilter('Tattoo', $searchFields);
    }

    /**
     * @param int $id
     * @param string $model
     * @return void|Tattoo
     */
    public function getById($id, $model = 'tattoo')
    {
        // Use Eloquent for simple ID lookups, Elasticsearch for more complex queries
        if ($id) {
            return Tattoo::where('id', $id)->first();
        }

        return null;
    }

    /**
     * Get tattoo by ID using Elasticsearch (for consistency with search functionality)
     */
    public function getByIdElastic(int $id)
    {
        return parent::getById($id, 'tattoo');
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

}
