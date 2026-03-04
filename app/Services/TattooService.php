<?php

namespace App\Services;

use App\Enums\ArtistTattooApprovalStatus;
use App\Enums\UserTypes;
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
        protected PaginationService $paginationService,
        public ImageService $imageService
    ) {
        parent::__construct($userService, $paginationService);
    }

    public function upload(array $files, User $user): array
    {
        $images = [];

        foreach ($files as $index => $file) {
            $date = Date('Ymdis');  // Added seconds for more uniqueness

            //get image extension
            $extension = $file->getClientOriginalExtension() ?: 'jpeg';

            // Include index to ensure unique filename for each image in batch upload
            $filename = "tattoo_" . $user->id . "_" . $date . "_" . $index . "." . $extension;
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

        if (isset($this->filters['booksOpen']) && $this->filters['booksOpen'] === true) {
            $this->search->where('artist_books_open', '=', true);
        }
    }

    /**
     * Build search string filter for tattoo-specific fields
     */
    private function buildTattooSearchStringFilter()
    {
        $searchFields = [
            'description',
            'artist_name',
            'studio_name',
            'uploader_name',
            'uploader_username',
        ];

        // Use the shared method from base class
        $this->buildSearchStringFilter('Tattoo', $searchFields);
    }

    /**
     * Create a tattoo record for either a client or artist upload.
     */
    public function createTattoo(User $user, array $data): Tattoo
    {
        $isClient = $user->type_id === UserTypes::CLIENT_TYPE_ID;

        if ($isClient) {
            $taggedArtistId = $data['tagged_artist_id'] ?? null;
            $approvalStatus = $taggedArtistId
                ? ArtistTattooApprovalStatus::PENDING
                : ArtistTattooApprovalStatus::USER_ONLY;
            $isVisible = $taggedArtistId ? false : true;

            return Tattoo::create([
                'artist_id' => $taggedArtistId,
                'uploaded_by_user_id' => $user->id,
                'approval_status' => $approvalStatus,
                'is_visible' => $isVisible,
                'is_demo' => (bool) $user->is_demo,
                'primary_image_id' => $data['primary_image_id'],
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'primary_style_id' => $data['primary_style_id'] ?? null,
                'attributed_artist_name' => $data['attributed_artist_name'] ?? null,
                'attributed_studio_name' => $data['attributed_studio_name'] ?? null,
                'attributed_location' => $data['attributed_location'] ?? null,
            ]);
        }

        return Tattoo::create([
            'artist_id' => $user->id,
            'uploaded_by_user_id' => $user->id,
            'approval_status' => ArtistTattooApprovalStatus::APPROVED,
            'is_visible' => true,
            'is_demo' => (bool) $user->is_demo,
            'primary_image_id' => $data['primary_image_id'],
            'studio_id' => $user->primary_studio?->id,
            'title' => $data['title'] ?? null,
            'description' => $data['description'] ?? null,
            'placement' => $data['placement'] ?? null,
            'duration' => $data['duration'] ?? null,
            'primary_style_id' => $data['primary_style_id'] ?? null,
        ]);
    }

    /**
     * @param int $id
     * @param string $model
     * @return void|Tattoo
     */
    public function getById($id, $model = 'tattoo')
    {
        try {
            $tattoo = Tattoo::search()->where('id', '=', $id)->get();
            if ($tattoo) {
                return $tattoo['response']->first();
            }
        } catch (\Exception $e) {
            \Log::error([
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile()
            ]);

            return null;
        }
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

    /**
     * Get all tattoos for a specific artist from the tattoos index
     * Sorted by: featured first, then newest first
     */
    public function getByArtistId(mixed $artistId, array $params = []): array
    {
        $this->filters = $params;
        $this->initializeSearch();

        $this->search->whereNot('is_visible', false);

        // Filter by artist_id or artist.slug
        if (!is_numeric($artistId)) {
            // It's a slug - query nested artist.slug field
            $this->search->where('artist_slug', '=', $artistId);
        } else {
            $this->search->where('artist_id', '=', (int)$artistId);
        }

        // Sort by: featured tattoos first, then newest uploads first
        $this->search->sort('is_featured', 'desc');
        $this->search->sort('created_at', 'desc');

        $this->applyPagination();

        return $this->search->get();
    }

    /**
     * Get multiple tattoos by their IDs from Elasticsearch
     */
    public function getByIds(array $ids): array
    {
        if (empty($ids)) {
            return ['response' => []];
        }

        $this->initializeSearch();
        $this->search->whereNot('is_visible', false);
        $this->search->where('id', 'in', $ids);

        return $this->search->get();
    }
}
