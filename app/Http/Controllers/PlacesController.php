<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class PlacesController extends Controller
{
    /**
     * Return the Google Places API key for frontend use
     * The key should be restricted by HTTP referrer in Google Cloud Console
     */
    public function config(): JsonResponse
    {
        $apiKey = config('services.google.places_api_key');

        if (!$apiKey) {
            return response()->json(['error' => 'Places API not configured'], 500);
        }

        return response()->json([
            'api_key' => $apiKey,
        ]);
    }
}
