<?php

namespace App\Services;

use App\Enums\ArtistTattooApprovalStatus;
use App\Enums\PostType;
use App\Enums\UserTypes;
use App\Exceptions\TattooNotFoundException;
use App\Jobs\IndexTattooJob;
use App\Jobs\NotifyNearbyArtistsOfBeacon;
use App\Models\Artist;
use App\Models\Tattoo;
use App\Models\TattooLead;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

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

        if (!empty($this->filters['post_type'])) {
            $this->search->where('post_type', '=', $this->filters['post_type']);
        }

        if (!empty($this->filters['post_types']) && is_array($this->filters['post_types'])) {
            $this->search->where('post_type', 'in', $this->filters['post_types']);
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

        $postType = $data['post_type'] ?? PostType::PORTFOLIO;

        if ($isClient) {
            // Seeking posts: visible, user_only, with linked beacon
            if ($postType === PostType::SEEKING) {
                $tattoo = Tattoo::create([
                    'uploaded_by_user_id' => $user->id,
                    'approval_status' => ArtistTattooApprovalStatus::USER_ONLY,
                    'is_visible' => true,
                    'is_demo' => (bool) $user->is_demo,
                    'post_type' => PostType::SEEKING,
                    'primary_image_id' => $data['primary_image_id'],
                    'title' => $data['title'] ?? null,
                    'description' => $data['description'] ?? null,
                    'primary_style_id' => $data['primary_style_id'] ?? null,
                ]);

                // Create linked beacon
                $lead = $this->createSeekingLead($user, $data);
                $tattoo->update(['tattoo_lead_id' => $lead->id]);

                return $tattoo;
            }

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
                'post_type' => PostType::PORTFOLIO,
                'primary_image_id' => $data['primary_image_id'],
                'title' => $data['title'] ?? null,
                'description' => $data['description'] ?? null,
                'primary_style_id' => $data['primary_style_id'] ?? null,
                'studio_id' => $data['studio_id'] ?? null,
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
            'post_type' => $postType,
            'flash_price' => $postType === PostType::FLASH ? ($data['flash_price'] ?? null) : null,
            'flash_size' => $postType === PostType::FLASH ? ($data['flash_size'] ?? null) : null,
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
     * Create a TattooLead linked to a seeking post and notify nearby artists.
     */
    private function createSeekingLead(User $user, array $data): TattooLead
    {
        // Deactivate existing active leads
        TattooLead::where('user_id', $user->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);

        // Use provided location or fall back to user's profile location
        $locationLatLong = $data['location_lat_long'] ?? $user->location_lat_long;
        $lat = null;
        $lng = null;
        if ($locationLatLong) {
            [$lat, $lng] = array_map('floatval', explode(',', $locationLatLong));
        }

        $lead = TattooLead::create([
            'user_id' => $user->id,
            'timing' => $data['timing'] ?? null,
            'interested_by' => TattooLead::calculateInterestedBy($data['timing'] ?? null),
            'allow_artist_contact' => $data['allow_artist_contact'] ?? true,
            'style_ids' => $data['style_ids'] ?? null,
            'tag_ids' => $data['tag_ids'] ?? null,
            'description' => $data['description'] ?? null,
            'is_active' => true,
            'lat' => $lat,
            'lng' => $lng,
            'location' => $data['seeking_location'] ?? $user->location,
            'location_lat_long' => $locationLatLong,
            'radius' => $data['seeking_radius'] ?? 50,
            'radius_unit' => $data['seeking_radius_unit'] ?? 'mi',
        ]);

        if ($lead->allow_artist_contact) {
            NotifyNearbyArtistsOfBeacon::dispatch($lead->id);
        }

        return $lead;
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

    /**
     * Delete tattoo DB records only (no ES removal, no S3 deletion).
     * Returns orphaned image data for async cleanup.
     *
     * @return array Array of ['image_id' => int, 'filename' => string] for orphaned images
     */
    public function deleteTattooDbOnly(Tattoo $tattoo): array
    {
        $images = $tattoo->images;

        $tattoo->images()->detach();
        $tattoo->styles()->detach();
        $tattoo->tags()->detach();
        $tattoo->delete();

        $orphanedImages = [];

        foreach ($images as $image) {
            $otherTattoosUsingImage = \DB::table('tattoos_images')
                ->where('image_id', $image->id)
                ->exists();

            $isPrimaryElsewhere = Tattoo::where('primary_image_id', $image->id)->exists();

            if (!$otherTattoosUsingImage && !$isPrimaryElsewhere) {
                $orphanedImages[] = [
                    'image_id' => $image->id,
                    'filename' => $image->filename,
                ];
            }
        }

        return $orphanedImages;
    }

    /**
     * Delete a tattoo: remove from ES, detach relations, delete record, clean up orphaned S3 images.
     *
     * @return int Number of images deleted from S3
     */
    public function deleteTattoo(Tattoo $tattoo): int
    {
        $tattoo->unsearchable();
        Cache::forget("es:tattoo:{$tattoo->id}");

        $images = $tattoo->images;

        $tattoo->images()->detach();
        $tattoo->styles()->detach();
        $tattoo->tags()->detach();
        $tattoo->delete();

        $storage = Storage::disk('s3');
        $deletedImageCount = 0;

        foreach ($images as $image) {
            $otherTattoosUsingImage = \DB::table('tattoos_images')
                ->where('image_id', $image->id)
                ->exists();

            $isPrimaryElsewhere = Tattoo::where('primary_image_id', $image->id)->exists();

            if (!$otherTattoosUsingImage && !$isPrimaryElsewhere) {
                if ($image->filename && $storage->exists($image->filename)) {
                    $storage->delete($image->filename);
                }
                $image->delete();
                $deletedImageCount++;
            }
        }

        // Re-index the artist if one exists
        if ($tattoo->artist_id) {
            $artist = Artist::find($tattoo->artist_id);
            if ($artist) {
                $artist->searchable();
                IndexTattooJob::bustArtistCaches($artist->id, $artist->slug);
            }
        }

        return $deletedImageCount;
    }
}
