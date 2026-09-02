<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\BriefArtistResource;
use App\Services\ArtistOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Builds an artist page from material the artist sent in by some route other
 * than the setup mailbox, usually a direct email.
 *
 * The account is provisional and claimable: the artist gets the same receipt
 * and temp password the inbound mailbox sends, so the page becomes theirs on
 * first login rather than staying something an admin owns.
 */
class ArtistOnboardingController extends Controller
{
    public function __construct(
        private ArtistOnboardingService $onboarding
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => 'required|email|max:255',
            'name' => 'required|string|max:255',
            'images' => 'required|array|min:1|max:60',
            'images.*.content' => 'required|string',
            'images.*.mime' => 'required|string|in:image/jpeg,image/png,image/gif,image/webp',
            'images.*.filename' => 'nullable|string|max:255',
            'images.*.size' => 'nullable|integer|min:0',
            'studio_id' => 'nullable|integer|exists:studios,id',
            'location' => 'nullable|string|max:255',
            // "lat,lng" from the Places picker. Without it the artist is absent
            // from proximity search and out of reach of the timezone backfill,
            // which derives from these coordinates.
            'location_lat_long' => ['nullable', 'string', 'max:64', 'regex:/^-?\d{1,3}(\.\d+)?,\s*-?\d{1,3}(\.\d+)?$/'],
        ]);

        [$artist, $bulkUpload, $processed, $isNewAccount] = $this->onboarding->onboard(
            $data['email'],
            $data['name'],
            $data['images'],
            'admin'
        );

        $this->onboarding->applyLocation(
            $artist,
            $data['location'] ?? null,
            $data['location_lat_long'] ?? null
        );

        $studio = ! empty($data['studio_id'])
            ? $this->onboarding->affiliateWithStudio($artist, $data['studio_id'])
            : null;

        Log::info('Admin onboarded an artist', [
            'artist_id' => $artist->id,
            'bulk_upload_id' => $bulkUpload->id,
            'processed' => $processed,
            'is_new_account' => $isNewAccount,
            'admin_id' => $request->user()->id,
        ]);

        return response()->json([
            'artist' => new BriefArtistResource($artist),
            'bulk_upload_id' => $bulkUpload->id,
            'images_saved' => $processed,
            'images_submitted' => count($data['images']),
            'is_new_account' => $isNewAccount,
            'studio' => $studio ? ['id' => $studio->id, 'name' => $studio->name] : null,
        ], 201);
    }
}
