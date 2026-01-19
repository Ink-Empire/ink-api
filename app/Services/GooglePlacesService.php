<?php

namespace App\Services;

use App\Models\Studio;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GooglePlacesService
{
    protected ?string $apiKey;
    protected int $cacheHours = 720; // 30 days

    public function __construct()
    {
        $this->apiKey = config('services.google.places_api_key');
    }

    /**
     * Search for tattoo parlors near a location and return/create unclaimed studios.
     */
    public function searchTattooParlors(string $latLong, int $radiusMeters = 25000, int $limit = 5): array
    {
        if (empty($this->apiKey)) {
            Log::warning('Google Places API key not configured');
            return [];
        }

        [$lat, $lng] = explode(',', $latLong);

        try {
            $response = Http::get('https://maps.googleapis.com/maps/api/place/nearbysearch/json', [
                'location' => "{$lat},{$lng}",
                'radius' => $radiusMeters,
                'keyword' => 'tattoo shop OR tattoo parlor OR tattoo studio',
                'key' => $this->apiKey,
            ]);

            if (!$response->successful()) {
                Log::error('Google Places API error', ['response' => $response->body()]);
                return [];
            }

            $data = $response->json();

            if ($data['status'] !== 'OK' && $data['status'] !== 'ZERO_RESULTS') {
                Log::error('Google Places API status error', ['status' => $data['status']]);
                return [];
            }

            $results = $data['results'] ?? [];
            $studios = [];

            // Place types that indicate this is NOT a tattoo shop
            $excludedTypes = [
                'lodging', 'hotel', 'motel', 'resort',
                'restaurant', 'cafe', 'bar', 'night_club',
                'hospital', 'doctor', 'dentist', 'pharmacy',
                'bank', 'atm', 'insurance_agency',
                'car_dealer', 'car_rental', 'car_repair', 'gas_station',
                'real_estate_agency', 'travel_agency',
                'supermarket', 'grocery_or_supermarket',
                'shopping_mall', 'department_store',
                'gym', 'stadium', 'movie_theater',
                'church', 'mosque', 'synagogue', 'hindu_temple',
                'school', 'university', 'library',
                'city_hall', 'courthouse', 'police', 'fire_station',
                'airport', 'bus_station', 'train_station', 'transit_station',
                'parking', 'post_office',
            ];

            foreach ($results as $place) {
                // Skip places with excluded types
                $placeTypes = $place['types'] ?? [];
                if (array_intersect($placeTypes, $excludedTypes)) {
                    continue;
                }

                $studio = $this->findOrCreateUnclaimedStudio($place);
                if ($studio) {
                    $studios[] = $studio;
                    if (count($studios) >= $limit) {
                        break;
                    }
                }
            }

            return $studios;
        } catch (\Exception $e) {
            Log::error('Google Places search error', ['message' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Find existing studio by google_place_id or create a new unclaimed one.
     */
    protected function findOrCreateUnclaimedStudio(array $place): ?Studio
    {
        $placeId = $place['place_id'] ?? null;
        if (!$placeId) {
            return null;
        }

        // Check if this place already exists (claimed or unclaimed)
        $existingStudio = Studio::where('google_place_id', $placeId)->first();

        if ($existingStudio) {
            // If it's claimed, don't return it (we'll show the claimed version from our search)
            if ($existingStudio->is_claimed) {
                return null;
            }
            // Update last fetched time for cache purposes
            $existingStudio->touch();
            return $existingStudio;
        }

        // Create new unclaimed studio
        $location = $place['geometry']['location'] ?? null;
        $lat = $location['lat'] ?? null;
        $lng = $location['lng'] ?? null;

        $studio = Studio::create([
            'name' => $place['name'] ?? 'Unknown Studio',
            'slug' => Str::slug($place['name'] ?? 'studio') . '-' . Str::random(6),
            'location' => $place['vicinity'] ?? null,
            'location_lat_long' => $lat && $lng ? "{$lat},{$lng}" : null,
            'google_place_id' => $placeId,
            'rating' => $place['rating'] ?? null,
            'is_claimed' => false,
        ]);

        return $studio;
    }

    /**
     * Get additional details for a place (phone, website, etc.)
     */
    public function getPlaceDetails(string $placeId): ?array
    {
        if (empty($this->apiKey)) {
            return null;
        }

        try {
            $response = Http::get('https://maps.googleapis.com/maps/api/place/details/json', [
                'place_id' => $placeId,
                'fields' => 'formatted_phone_number,website,opening_hours,formatted_address',
                'key' => $this->apiKey,
            ]);

            if (!$response->successful()) {
                return null;
            }

            $data = $response->json();
            return $data['result'] ?? null;
        } catch (\Exception $e) {
            Log::error('Google Places details error', ['message' => $e->getMessage()]);
            return null;
        }
    }
}
