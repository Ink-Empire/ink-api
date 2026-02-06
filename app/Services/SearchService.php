<?php

namespace App\Services;

use App\Enums\SearchContext;
use App\Models\Tattoo;
use App\Util\StringToModel;

abstract class SearchService
{
    protected $filters = [];
    protected $search;
    protected $user;
    protected $latLongString;
    protected $searchContext;

    public function __construct(
        protected UserService $userService,
        protected PaginationService $paginationService
    ) {
        $this->searchContext = $this->getSearchContext();
    }

    /**
     * Get the search context (tattoo, artist, etc.) - to be implemented by child classes
     */
    abstract protected function getSearchContext(): string;

    public function getById($id, $model)
    {
        //if id is numeric:
        if (!is_numeric($id)) {
            $field = 'slug';
        } else {
            $field = 'id';
        }

        $model = StringToModel::convert(ucfirst($model));

        $this->search = $model->search();

        $response = $this->search->where($field, $id)->get();

        if (!empty($response)) {
            return collect($response['response'])->first();
        }
    }

    /**
     * Common search method that child classes can extend
     */
    public function search($params)
    {
        $this->filters = $params;
        $this->initializeSearch();

        if (isset($this->filters['user_id'])) {
            $this->user = $this->userService->getById($this->filters['user_id']);
        }

        $this->applyCommonFilters();
        $this->applySpecificFilters();
        $this->applySorting();
        $this->applyPagination();

        return $this->search->get();
    }

    /**
     * Apply pagination to the search query
     */
    protected function applyPagination(): void
    {
        $pagination = $this->paginationService->extractParams($this->filters);

        $this->search->from($pagination['offset']);
        $this->search->take($pagination['per_page']);
    }

    /**
     * Apply sorting based on the sort parameter
     * Supported values: popular, recent, nearest
     * Default: featured first, then recent
     */
    protected function applySorting(): void
    {
        $sort = $this->filters['sort'] ?? null;

        switch ($sort) {
            case 'popular':
                $this->search->sort('saved_count', 'desc');
                $this->search->sort('created_at', 'desc');
                break;

            case 'recent':
                $this->search->sort('created_at', 'desc');
                break;

            case 'nearest':
                $this->applyNearestSort();
                break;

            default:
                // Default: featured first, then recent
                $this->search->sort('is_featured', 'desc');
                $this->search->sort('created_at', 'desc');
                break;
        }
    }

    /**
     * Apply nearest/distance-based sorting
     */
    protected function applyNearestSort(): void
    {
        $locationField = $this->getDistanceField();

        // Try to get location from various sources
        $latLongString = null;

        if (isset($this->filters['locationCoords'])) {
            $latLongString = $this->filters['locationCoords'];
        } elseif (isset($this->filters['useMyLocation']) && $this->filters['useMyLocation'] && $this->user) {
            $latLongString = $this->user->location_lat_long;
        } elseif ($this->latLongString) {
            $latLongString = $this->latLongString;
        }

        if ($latLongString) {
            $this->buildGeoParam($locationField, $latLongString);
        } else {
            // Fallback to recent if no location available
            $this->search->sort('created_at', 'desc');
        }
    }

    /**
     * Initialize the search object - to be implemented by child classes
     */
    abstract protected function initializeSearch();

    /**
     * Apply filters specific to the model - to be implemented by child classes
     */
    protected function applySpecificFilters()
    {
        // Default implementation - can be overridden by child classes
    }

    /**
     * Apply common filters that work across models
     */
    protected function applyCommonFilters()
    {
        // Handle demo mode filtering:
        if (isset($this->filters['is_demo']) && $this->filters['is_demo']) {
            // Demo mode: show only demo data
            $this->search->where('is_demo', 'in', [true]);
        } elseif (!isset($this->filters['include_demo']) || !$this->filters['include_demo']) {
            // Default: filter out demo data
            $this->search->where('is_demo', 'in', [false]);
        }
        // If include_demo is true, no filter is applied (show all data)

        if (isset($this->filters['studio_id'])) {
            $this->buildStudioParam();
        }

        if (isset($this->filters['styles'])) {
            $this->buildStylesParam();
        }

        if (isset($this->filters['tags']) && !empty($this->filters['tags'])) {
            $this->buildTagsParam();
        }

        if (isset($this->filters['tagNames']) && !empty($this->filters['tagNames'])) {
            $this->buildTagNamesParam();
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

        // Handle distance-based filtering with coordinates
        // Skip distance filtering if user wants "anywhere" (useAnyLocation: true)
        if (!(isset($this->filters['useAnyLocation']) && $this->filters['useAnyLocation'])) {
            // Determine the location coordinates to use
            if (isset($this->filters['useMyLocation']) && $this->filters['useMyLocation'] && $this->user) {
                $this->latLongString = $this->user->location_lat_long;
            } elseif (isset($this->filters['locationCoords'])) {
                $this->latLongString = $this->filters['locationCoords'];
            }

            // Apply distance filter if we have coordinates AND distance settings
            if ($this->latLongString && isset($this->filters['distance']) && isset($this->filters['distanceUnit'])) {
                $distanceParam = $this->getDistanceField();
                $this->buildDistanceParam($distanceParam, $this->latLongString);
            }
        }
    }

    /**
     * Get the field to use for distance calculations - can be overridden by child classes
     */
    protected function getDistanceField(): string
    {
        if ($this->searchContext === SearchContext::TATTOO) {
            return 'artist_location_lat_long';
        }
        return 'location_lat_long';
    }

    /**
     * Build geo sorting parameter
     */
    protected function buildGeoParam($field = 'location_lat_long', string $latLongString = null): void
    {
        try {
            if (empty($latLongString) && isset($this->user)) {
                $latLongArray = explode(",", $this->user->location_lat_long);
            } else {
                $latLongArray = explode(",", $latLongString);
            }

            if (count($latLongArray) >= 2) {
                $data = [
                    'field' => $field,
                    'lat' => $latLongArray[0],
                    'lon' => $latLongArray[1]
                ];

                $this->search->geoSort($data);
            }
        } catch (\Exception $e) {
            \Log::error("Unable to build geo param", [
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
                'user_id' => $this->user->id ?? "user not found",
            ]);
        }
    }

    /**
     * Build studio parameter filter
     */
    protected function buildStudioParam(): void
    {
        $this->search->where('studio.id', $this->filters['studio_id']);
    }

    /**
     * Build styles parameter filter
     */
    protected function buildStylesParam($minMatch = 1): void
    {
        $clauses = [];
        $styles = $this->filters['styles'];

        // Ensure styles is always an array
        if (!is_array($styles)) {
            $styles = [$styles];
        }

        // Build clauses for each style
        foreach ($styles as $style) {
            if ($style) {
                $clauses[] = ['styles.id', '=', $style];
            }
        }

        if (count($clauses) > 0) {
            $this->search->orWhere($clauses, $minMatch);
        }
    }

    /**
     * Build tags parameter filter - requires ALL selected tags to match
     */
    protected function buildTagsParam(): void
    {
        $tagIds = $this->filters['tags'];

        // Ensure tags is always an array
        if (!is_array($tagIds)) {
            $tagIds = [$tagIds];
        }

        // Filter out empty values
        $tagIds = array_filter($tagIds);

        if (count($tagIds) > 0) {
            // Look up tag names from IDs (tags are indexed as name strings, not objects)
            $tagNames = \App\Models\Tag::whereIn('id', $tagIds)->pluck('name')->toArray();

            if (count($tagNames) > 0) {
                // Each tag must match (AND logic) - use where for each tag name
                foreach ($tagNames as $tagName) {
                    $this->search->where('tags', '=', $tagName);
                }
            }
        }
    }

    /**
     * Build tag names parameter filter - filter by tag names directly (no ID lookup)
     */
    protected function buildTagNamesParam(): void
    {
        $tagNames = $this->filters['tagNames'];

        // Ensure tagNames is always an array
        if (!is_array($tagNames)) {
            $tagNames = [$tagNames];
        }

        // Filter out empty values
        $tagNames = array_filter($tagNames);

        if (count($tagNames) > 0) {
            // Each tag must match (AND logic) - use where for each tag name
            foreach ($tagNames as $tagName) {
                $this->search->where('tags', '=', $tagName);
            }
        }
    }


    private function buildDistanceParam($field = 'location_lat_long', string $latLongString = null): void
    {
        try {
            $distance = $this->filters['distance'] . $this->filters['distanceUnit'];
            $latLongArray = explode(",", $latLongString);

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

        $distanceSearch->whereDistance('artist_location_lat_long', $latLongArray[0], $latLongArray[1], $distance);

        $distanceResponse = $distanceSearch->get();


        return $styleResponse['response']->merge($distanceResponse['response']);

    }

    //this creates opposing queries and nests them as THIS or THAT. Prime example: WUB + 1 and 4 color cards.
    private function getInitialNestedUserQuery(): void
    {
        $searchClauseArray = [
            'style_clause' => $this->getUserStylesOrSyntax(1),
            'savedArtistClause' => $this->getSavedArtistsOrSyntax(1),
            'artistsNearMeClause' => $this->getArtistsNearMeSyntax(),
        ];

        $minMatch = 1;

        foreach ($searchClauseArray as $key => $clause) {
            if ($clause) {
                $this->search->nestedOr(
                    [
                        $clause
                    ], $minMatch
                );
            }
        }
    }

    private function getUserStylesOrSyntax($minMatch = 1): ?array
    {
        $styles_clauses = collect($this->user->styles)
            ->map(function ($value) {
                return ['styles.id', '=', $value->id];
            })->toArray();

        if (count($styles_clauses) > 0) {

            $response['bool']['minimum_should_match'] = $minMatch;
            $response['bool']['should'] = $this->search->orWhereSyntax($styles_clauses, $minMatch);

            return $response;
        }
        return null;
    }

    private function getSavedArtistsOrSyntax($minMatch = 1): ?array
    {
        $faves_clauses = collect($this->user->artists)
            ->map(function ($value) {
                return ['artist_id', '=', $value->id];
            })->toArray();

        if (count($faves_clauses) > 0) {

            $response['bool']['minimum_should_match'] = $minMatch;
            $response['bool']['should'] = $this->search->orWhereSyntax($faves_clauses, $minMatch);

            return $response;
        }
        return null;
    }

    private function getArtistsNearMeSyntax(): array
    {
        $latLongArray = explode(",", $this->user->location_lat_long);

        $response['bool']['must'] = $this->search->whereDistanceSyntax('artist_location_lat_long', $latLongArray[0], $latLongArray[1], '25mi');

        return $response;
    }

    /**
     * Build search string filter for specified model and fields.
     * Creates one large OR query that matches:
     * - Intervals queries on text fields (description, artist_name, studio_name)
     * - Terms queries for each word against tags
     * - Nested terms queries for each word against styles.name
     *
     * @param string $modelClass The model class name (e.g., 'Artist', 'Tattoo')
     * @param array $fields Array of field names to search in
     * @param string $searchString The search string to filter by (optional, uses $this->filters['searchString'] if not provided)
     * @param int $minMatch Minimum number of matches required (default: 1)
     */
    protected function buildSearchStringFilter(string $modelClass, array $fields, string $searchString = null, int $minMatch = 1): void
    {
        // Use provided search string or get from filters
        $searchText = $searchString ?? ($this->filters['searchString'] ?? null);

        if (empty($searchText) || empty($fields)) {
            return;
        }

        // Get the full model class with namespace
        $fullModelClass = "App\\Models\\{$modelClass}";

        // Create a generic search object to construct clauses we need
        $query = $fullModelClass::search();

        // Build intervals queries for each text field (high priority matches)
        foreach ($fields as $field) {
            $query->wherePrefix($field, $searchText, 'all_of', true);
        }

        // Check full search text against tags
        $query->where('tags', 'in', [$searchText]);

        // Split search string into individual words for tag/style matching
        $words = array_filter(
            explode(' ', strtolower(trim($searchText))),
            fn($word) => strlen(trim($word)) >= 2
        );

        // Add terms query for each word against tags
        foreach ($words as $word) {
            $word = trim($word);
            if (!empty($word)) {
                $query->where('tags', 'in', [$word]);
            }
        }

        // Add nested terms queries for each word against styles.name
        foreach ($words as $word) {
            $word = trim($word);
            if (!empty($word)) {
                $query->where('styles.name', 'in', [$word]);
                $query->where('placement', 'in',[$word]); //in case they want to see all shoulder tats
            }
        }

        // Add the complete OR query to the main search with minimum match
        // All the above clauses get wrapped in one should block
        $this->search->orWhere($query, $minMatch);
    }
}
